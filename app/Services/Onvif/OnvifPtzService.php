<?php

namespace App\Services\Onvif;

use App\Models\Camera;
use App\Services\CameraLiveStreamService;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class OnvifPtzService
{
    private const DEVICE = 'http://www.onvif.org/ver10/device/wsdl';

    private const MEDIA = 'http://www.onvif.org/ver10/media/wsdl';

    private const PTZ = 'http://www.onvif.org/ver20/ptz/wsdl';

    public function __construct(private OnvifPtzSoapClient $soap, private CameraLiveStreamService $streams) {}

    /** Only these public capability flags leave the server. */
    public function capabilities(Camera $camera): array
    {
        $configuration = $this->configuration($camera);

        return [
            'supported' => $configuration !== null,
            'pan_tilt' => isset($configuration['pan_tilt']),
            'zoom' => isset($configuration['zoom']),
        ];
    }

    public function move(Camera $camera, string $command): void
    {
        $configuration = $this->configuration($camera);
        if ($configuration === null) {
            throw new RuntimeException('This camera does not expose supported ONVIF PTZ controls.');
        }
        $profile = $this->soap->escape($configuration['profile']);
        if ($command === 'stop') {
            $body = '<tptz:Stop><tptz:ProfileToken>'.$profile.'</tptz:ProfileToken><tptz:PanTilt>true</tptz:PanTilt><tptz:Zoom>true</tptz:Zoom></tptz:Stop>';
            $operation = 'Stop';
        } else {
            $directions = ['up' => [0, 1], 'down' => [0, -1], 'left' => [-1, 0], 'right' => [1, 0],
                'up-left' => [-1, 1], 'up-right' => [1, 1], 'down-left' => [-1, -1], 'down-right' => [1, -1]];
            if (isset($directions[$command]) && isset($configuration['pan_tilt'])) {
                [$x, $y] = $directions[$command];
                $space = $configuration['pan_tilt'];
                $x = $this->velocity($x, $space['x']);
                $y = $this->velocity($y, $space['y']);
                $velocity = '<tt:PanTilt x="'.$x.'" y="'.$y.'" space="'.$this->soap->escape($space['uri']).'" />';
            } elseif (in_array($command, ['zoom-in', 'zoom-out'], true) && isset($configuration['zoom'])) {
                $space = $configuration['zoom'];
                $x = $this->velocity($command === 'zoom-in' ? 1 : -1, $space['x']);
                $velocity = '<tt:Zoom x="'.$x.'" space="'.$this->soap->escape($space['uri']).'" />';
            } else {
                throw new RuntimeException('This movement is not supported by the camera.');
            }
            $body = '<tptz:ContinuousMove><tptz:ProfileToken>'.$profile.'</tptz:ProfileToken><tptz:Velocity>'.$velocity.'</tptz:Velocity><tptz:Timeout>PT1S</tptz:Timeout></tptz:ContinuousMove>';
            $operation = 'ContinuousMove';
        }
        $this->soap->request($camera, $configuration['url'], self::PTZ, $operation, $body);
    }

    private function configuration(Camera $camera): ?array
    {
        if (! $camera->is_enabled || $camera->onvifEndpoint() === null) {
            return null;
        }
        $key = 'onvif-ptz:'.hash('sha256', json_encode([
            $camera->getKey(), $camera->onvifEndpoint(), $camera->username, $camera->password,
            $camera->rtspProfiles(), $camera->rtsp_path, $camera->supports_rtsp,
        ], JSON_THROW_ON_ERROR));
        $cached = Cache::get($key);
        if (is_array($cached)) {
            if (isset($cached['error'])) {
                throw new RuntimeException($cached['error']);
            }

            return $cached['configuration'];
        }
        try {
            $configuration = $this->discover($camera);
        } catch (RuntimeException $exception) {
            Cache::put($key, ['error' => $exception->getMessage()], 30);
            throw $exception;
        }
        Cache::put($key, ['configuration' => $configuration], $configuration === null ? 300 : 600);

        return $configuration;
    }

    private function discover(Camera $camera): ?array
    {
        $capabilities = $this->soap->request($camera, $camera->onvifEndpoint(), self::DEVICE, 'GetCapabilities', '<tds:GetCapabilities><tds:Category>All</tds:Category></tds:GetCapabilities>');
        $ptzUrl = $this->value($capabilities, '//*[local-name()="Capabilities"]/*[local-name()="PTZ"]/*[local-name()="XAddr"]');
        if ($ptzUrl === '') {
            return null;
        }
        $mediaUrl = $this->value($capabilities, '//*[local-name()="Capabilities"]/*[local-name()="Media"]/*[local-name()="XAddr"]');
        $this->assertServiceUrl($ptzUrl);
        $this->assertServiceUrl($mediaUrl);
        $profiles = $this->soap->request($camera, $mediaUrl, self::MEDIA, 'GetProfiles', '<trt:GetProfiles />');
        $preferredToken = $this->streams->selectWallProfile($camera)['profile']['token'] ?? null;
        $candidates = [];
        foreach ($profiles->query('//*[local-name()="Profiles" or local-name()="Profile"]') as $profile) {
            if (! $profile instanceof DOMElement) {
                continue;
            }
            $configuration = $this->value($profiles, './*[local-name()="PTZConfiguration"]/@token', $profile);
            if ($configuration !== '' && $profile->getAttribute('token') !== '') {
                $candidates[] = ['profile' => $profile->getAttribute('token'), 'configuration' => $configuration];
            }
        }
        usort($candidates, fn ($a, $b) => (int) ($b['profile'] === $preferredToken) <=> (int) ($a['profile'] === $preferredToken));
        foreach ($candidates as $candidate) {
            $options = $this->soap->request($camera, $ptzUrl, self::PTZ, 'GetConfigurationOptions', '<tptz:GetConfigurationOptions><tptz:ConfigurationToken>'.$this->soap->escape($candidate['configuration']).'</tptz:ConfigurationToken></tptz:GetConfigurationOptions>');
            // Hide controls when the camera cannot accept a bounded one-second move.
            $minTimeout = $this->value($options, '//*[local-name()="PTZTimeout"]/*[local-name()="Min"]');
            $maxTimeout = $this->value($options, '//*[local-name()="PTZTimeout"]/*[local-name()="Max"]');
            if (($minTimeout !== '' && $this->duration($minTimeout) > 1) || ($maxTimeout !== '' && $this->duration($maxTimeout) < 1)) {
                continue;
            }
            $result = ['url' => $ptzUrl, 'profile' => $candidate['profile']];
            foreach (['pan_tilt' => 'ContinuousPanTiltVelocitySpace', 'zoom' => 'ContinuousZoomVelocitySpace'] as $axis => $element) {
                foreach ($options->query('//*[local-name()="Spaces"]/*[local-name()="'.$element.'"]') as $space) {
                    $uri = $this->value($options, './*[local-name()="URI"]', $space);
                    $x = $this->range($options, $space, 'XRange');
                    $y = $axis === 'pan_tilt' ? $this->range($options, $space, 'YRange') : null;
                    if ($uri !== '' && $x !== null && ($axis === 'zoom' || $y !== null)) {
                        $result[$axis] = ['uri' => $uri, 'x' => $x, 'y' => $y];
                        break;
                    }
                }
            }
            if (isset($result['pan_tilt']) || isset($result['zoom'])) {
                return $result;
            }
        }

        return null;
    }

    private function value(DOMXPath $xpath, string $query, ?\DOMNode $node = null): string
    {
        return trim((string) $xpath->evaluate('string('.$query.')', $node));
    }

    private function range(DOMXPath $xpath, \DOMNode $node, string $name): ?array
    {
        $min = $this->value($xpath, './*[local-name()="'.$name.'"]/*[local-name()="Min"]', $node);
        $max = $this->value($xpath, './*[local-name()="'.$name.'"]/*[local-name()="Max"]', $node);

        return is_numeric($min) && is_numeric($max) && (float) $min < 0 && (float) $max > 0
            ? [(float) $min, (float) $max] : null;
    }

    private function velocity(int $direction, array $range): float
    {
        return $direction === 0 ? 0 : ($direction < 0 ? $range[0] : $range[1]) * 0.35;
    }

    private function duration(string $value): float
    {
        if (! preg_match('/^P(?:(\d+)D)?T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+(?:\.\d+)?)S)?$/', $value, $parts)) {
            throw new RuntimeException('The camera returned invalid PTZ timeout options.');
        }

        return (float) ($parts[1] ?? 0) * 86400 + (float) ($parts[2] ?? 0) * 3600
            + (float) ($parts[3] ?? 0) * 60 + (float) ($parts[4] ?? 0);
    }

    private function assertServiceUrl(string $url): void
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new RuntimeException('The camera returned an invalid ONVIF service address.');
        }
    }
}
