<?php
return array (
  'cameras' => 
  array (
    0 => 
    array (
      'uuid' => '019d6951-b248-71cb-a3b1-5ac917d5c731',
      'name' => 'Kitchen',
      'local_ip' => '192.168.1.66',
      'hostname' => NULL,
      'manufacturer' => 'tp-link',
      'model' => 'Tapo C200',
      'serial_number' => '210129b3',
      'mac_address' => NULL,
      'http_port' => 2020,
      'onvif_port' => 2020,
      'rtsp_port' => 554,
      'onvif_path' => '/onvif/device_service',
      'rtsp_path' => '/stream2',
      'rtsp_transport' => 'tcp',
      'username' => 'rekanized',
      'password' => 'eyJpdiI6ImhSYjBadWpQckh4bzN6RkU0Vk5LNkE9PSIsInZhbHVlIjoibVhJUkpKUExrajNTQ29STnR2VGc4QT09IiwibWFjIjoiZGQwMDkzOWM4NTg0YzA2ODg4ZjAwZGQ1NWRkZjk3MGFjZTYwZjFlNzg4Yjc4ZjE0Nzg0OThmODZkOWNiOWFkZCIsInRhZyI6IiJ9',
      'supports_onvif' => true,
      'supports_rtsp' => true,
      'is_enabled' => true,
      'recording_mode' => 'motion',
      'recording_profile_index' => 1,
      'recording_retention_days' => 30,
      'motion_sensitivity' => 2,
      'recording_motion_pre_roll_seconds' => 4,
      'recording_motion_post_trigger_seconds' => 20,
      'recording_motion_area' => 
      array (
        'x' => 0,
        'y' => 0,
        'width' => 100,
        'height' => 100,
      ),
      'recording_motion_mask' => 
      array (
        'version' => 1,
        'grid_width' => 160,
        'grid_height' => 90,
        'selected_pixels' => 14400,
        'runs' => 
        array (
          0 => 
          array (
            0 => 0,
            1 => 14399,
          ),
        ),
      ),
      'recording_last_motion_at' => '2026-04-11 08:48:17',
      'recording_last_recorded_at' => '2026-04-11 08:48:37',
      'last_seen_at' => '2026-04-11 09:00:06',
      'metadata' => 
      array (
        'onvif' => 
        array (
          'service_url' => 'http://192.168.1.66:2020/onvif/device_service',
          'response_time_ms' => 14,
          'firmware_version' => '1.0.17 Build 240806 Rel.39518n',
          'hardware_id' => '5.0',
          'http_status' => 200,
          'authenticated' => true,
          'response_excerpt' => 'urn:uuid:bc5354e9-9c91-4f11-8031-22151bd224e1http://www.w3.org/2005/08/addressing/anonymousttlhttp://192.168.1.66:2020/onvif/device_servicehttp://www.onvif.org/ver10/device/wsdl/GetDeviceInformationtp-linkTapo C2001.0.17 Build 240806 Rel.39...',
          'last_verified_at' => '2026-04-07T19:00:54+00:00',
          'media_service_url' => 'http://192.168.1.66:2020/onvif/service',
          'last_rtsp_sync_at' => '2026-04-07T22:00:40+00:00',
        ),
        'rtsp_profiles' => 
        array (
          0 => 
          array (
            'token' => 'profile_1',
            'name' => 'mainStream',
            'encoding' => 'H264',
            'resolution' => '1920x1080',
            'uri' => 'rtsp://192.168.1.66:554/stream1',
            'path' => '/stream1',
          ),
          1 => 
          array (
            'token' => 'profile_2',
            'name' => 'minorStream',
            'encoding' => 'H264',
            'resolution' => '1280x720',
            'uri' => 'rtsp://192.168.1.66:554/stream2',
            'path' => '/stream2',
            'probe_status' => 'Healthy',
            'probe_checked_at' => '2026-04-11 09:00:02 UTC',
            'probe_message' => 'RTSP connection confirmed from this host.',
            'video_codec' => 'h264',
            'video_resolution' => '1280x720',
            'preview_path' => 'cameras/1/previews/minorstream-2.jpg',
            'preview_generated_at' => '2026-04-11 09:00:02 UTC',
            'preview_message' => 'Snapshot captured successfully.',
            'transport' => 'TCP',
          ),
        ),
        'preview_maintenance' => 
        array (
          'last_attempted_at' => '2026-04-11T09:00:06+00:00',
          'last_profile_index' => 1,
        ),
      ),
      'created_at' => '2026-04-07 19:00:54',
      'updated_at' => '2026-04-11 09:00:06',
    ),
    1 => 
    array (
      'uuid' => '019d6953-60f6-70cf-91ee-ef1458471382',
      'name' => 'Hallway 2nd floor',
      'local_ip' => '192.168.1.71',
      'hostname' => NULL,
      'manufacturer' => 'tp-link',
      'model' => 'Tapo C200',
      'serial_number' => '2101427c',
      'mac_address' => NULL,
      'http_port' => 2020,
      'onvif_port' => 2020,
      'rtsp_port' => 554,
      'onvif_path' => '/onvif/device_service',
      'rtsp_path' => '/stream2',
      'rtsp_transport' => 'tcp',
      'username' => 'rekanized',
      'password' => 'eyJpdiI6ImlyUlpGZXA5ejBLMWlUV1B2ZmoraFE9PSIsInZhbHVlIjoiU2hNUHZBcElOd0gzeWR1cFdiNFkyUT09IiwibWFjIjoiZGEyZmI2MDQ0YzhlMTRjZTBmYzg3M2IxMWQwMTIzYmQyMTJhOWQzN2E1NDhlYjZhZGE0MjBmYjMyM2I1NDM2YiIsInRhZyI6IiJ9',
      'supports_onvif' => true,
      'supports_rtsp' => true,
      'is_enabled' => true,
      'recording_mode' => 'motion',
      'recording_profile_index' => NULL,
      'recording_retention_days' => 30,
      'motion_sensitivity' => 2,
      'recording_motion_pre_roll_seconds' => 4,
      'recording_motion_post_trigger_seconds' => 20,
      'recording_motion_area' => 
      array (
        'x' => 0,
        'y' => 0,
        'width' => 100,
        'height' => 100,
      ),
      'recording_motion_mask' => 
      array (
        'version' => 1,
        'grid_width' => 160,
        'grid_height' => 90,
        'selected_pixels' => 14400,
        'runs' => 
        array (
          0 => 
          array (
            0 => 0,
            1 => 14399,
          ),
        ),
      ),
      'recording_last_motion_at' => '2026-04-11 09:05:33',
      'recording_last_recorded_at' => '2026-04-11 09:05:53',
      'last_seen_at' => '2026-04-11 09:00:11',
      'metadata' => 
      array (
        'onvif' => 
        array (
          'service_url' => 'http://192.168.1.71:2020/onvif/device_service',
          'response_time_ms' => 29,
          'firmware_version' => '1.0.17 Build 240806 Rel.39518n',
          'hardware_id' => '5.0',
          'http_status' => 200,
          'authenticated' => true,
          'response_excerpt' => 'urn:uuid:261cbba9-f60b-4d8e-8675-93aadcc4cca8http://www.w3.org/2005/08/addressing/anonymousttlhttp://192.168.1.71:2020/onvif/device_servicehttp://www.onvif.org/ver10/device/wsdl/GetDeviceInformationtp-linkTapo C2001.0.17 Build 240806 Rel.39...',
          'last_verified_at' => '2026-04-07T19:02:45+00:00',
          'media_service_url' => 'http://192.168.1.71:2020/onvif/service',
          'last_rtsp_sync_at' => '2026-04-07T19:03:17+00:00',
        ),
        'rtsp_profiles' => 
        array (
          0 => 
          array (
            'token' => 'profile_1',
            'name' => 'mainStream',
            'encoding' => 'H264',
            'resolution' => '1920x1080',
            'uri' => 'rtsp://192.168.1.71:554/stream1',
            'path' => '/stream1',
          ),
          1 => 
          array (
            'token' => 'profile_2',
            'name' => 'minorStream',
            'encoding' => 'H264',
            'resolution' => '1280x720',
            'uri' => 'rtsp://192.168.1.71:554/stream2',
            'path' => '/stream2',
            'probe_status' => 'Healthy',
            'probe_checked_at' => '2026-04-11 09:00:06 UTC',
            'probe_message' => 'RTSP connection confirmed from this host.',
            'video_codec' => 'h264',
            'video_resolution' => '1280x720',
            'preview_path' => 'cameras/2/previews/minorstream-2.jpg',
            'preview_generated_at' => '2026-04-11 09:00:06 UTC',
            'preview_message' => 'Snapshot captured successfully.',
            'transport' => 'TCP',
          ),
        ),
        'preview_maintenance' => 
        array (
          'last_attempted_at' => '2026-04-11T09:00:11+00:00',
          'last_profile_index' => 1,
        ),
      ),
      'created_at' => '2026-04-07 19:02:45',
      'updated_at' => '2026-04-11 09:06:22',
    ),
    2 => 
    array (
      'uuid' => '019d6957-9fd1-7160-957c-f97c18f4f7b2',
      'name' => 'Kids playroom',
      'local_ip' => '192.168.1.68',
      'hostname' => NULL,
      'manufacturer' => 'tp-link',
      'model' => 'Tapo C200',
      'serial_number' => '8426b75d',
      'mac_address' => NULL,
      'http_port' => 2020,
      'onvif_port' => 2020,
      'rtsp_port' => 554,
      'onvif_path' => '/onvif/device_service',
      'rtsp_path' => '/stream2',
      'rtsp_transport' => 'tcp',
      'username' => 'rekanized',
      'password' => 'eyJpdiI6IjdWZUdBaHh1Zis2dlNkcEtHdVgwalE9PSIsInZhbHVlIjoiZHo3dVJJbjBkNW9nYXRqeUh6VCtyQT09IiwibWFjIjoiYjVkNjdjZTZmZTNjM2NhYTNiZjI4MjQwZGUwOGJhNTQzZDY5OTQ5MDU2MmExMmU4YWMwOGEyMmJmMGQwYjMyMSIsInRhZyI6IiJ9',
      'supports_onvif' => true,
      'supports_rtsp' => true,
      'is_enabled' => true,
      'recording_mode' => 'motion',
      'recording_profile_index' => NULL,
      'recording_retention_days' => 30,
      'motion_sensitivity' => 2,
      'recording_motion_pre_roll_seconds' => 4,
      'recording_motion_post_trigger_seconds' => 20,
      'recording_motion_area' => 
      array (
        'x' => 0,
        'y' => 0,
        'width' => 100,
        'height' => 100,
      ),
      'recording_motion_mask' => 
      array (
        'version' => 1,
        'grid_width' => 160,
        'grid_height' => 90,
        'selected_pixels' => 14400,
        'runs' => 
        array (
          0 => 
          array (
            0 => 0,
            1 => 14399,
          ),
        ),
      ),
      'recording_last_motion_at' => '2026-04-11 08:15:21',
      'recording_last_recorded_at' => '2026-04-11 08:15:41',
      'last_seen_at' => '2026-04-11 09:00:16',
      'metadata' => 
      array (
        'onvif' => 
        array (
          'service_url' => 'http://192.168.1.68:2020/onvif/device_service',
          'response_time_ms' => 25,
          'firmware_version' => '1.0.17 Build 240806 Rel.39518n',
          'hardware_id' => '5.0',
          'http_status' => 200,
          'authenticated' => true,
          'response_excerpt' => 'urn:uuid:c9c76c34-d333-4361-87e7-424aa7492517http://www.w3.org/2005/08/addressing/anonymousttlhttp://192.168.1.68:2020/onvif/device_servicehttp://www.onvif.org/ver10/device/wsdl/GetDeviceInformationtp-linkTapo C2001.0.17 Build 240806 Rel.39...',
          'last_verified_at' => '2026-04-07T19:07:23+00:00',
          'media_service_url' => 'http://192.168.1.68:2020/onvif/service',
          'last_rtsp_sync_at' => '2026-04-07T19:07:37+00:00',
        ),
        'rtsp_profiles' => 
        array (
          0 => 
          array (
            'token' => 'profile_1',
            'name' => 'mainStream',
            'encoding' => 'H264',
            'resolution' => '1920x1080',
            'uri' => 'rtsp://192.168.1.68:554/stream1',
            'path' => '/stream1',
          ),
          1 => 
          array (
            'token' => 'profile_2',
            'name' => 'minorStream',
            'encoding' => 'H264',
            'resolution' => '1280x720',
            'uri' => 'rtsp://192.168.1.68:554/stream2',
            'path' => '/stream2',
            'probe_status' => 'Healthy',
            'probe_checked_at' => '2026-04-11 09:00:11 UTC',
            'probe_message' => 'RTSP connection confirmed from this host.',
            'video_codec' => 'h264',
            'video_resolution' => '1280x720',
            'preview_path' => 'cameras/3/previews/minorstream-2.jpg',
            'preview_generated_at' => '2026-04-11 09:00:11 UTC',
            'preview_message' => 'Snapshot captured successfully.',
            'transport' => 'TCP',
          ),
        ),
        'preview_maintenance' => 
        array (
          'last_attempted_at' => '2026-04-11T09:00:16+00:00',
          'last_profile_index' => 1,
        ),
      ),
      'created_at' => '2026-04-07 19:07:23',
      'updated_at' => '2026-04-11 09:00:16',
    ),
    3 => 
    array (
      'uuid' => '019d695a-37dd-738b-bbcd-dec29822d7e6',
      'name' => 'Kids bedroom',
      'local_ip' => '192.168.1.67',
      'hostname' => NULL,
      'manufacturer' => 'tp-link',
      'model' => 'Tapo C200',
      'serial_number' => '213ba426',
      'mac_address' => NULL,
      'http_port' => 2020,
      'onvif_port' => 2020,
      'rtsp_port' => 554,
      'onvif_path' => '/onvif/device_service',
      'rtsp_path' => '/stream2',
      'rtsp_transport' => 'tcp',
      'username' => 'rekanized',
      'password' => 'eyJpdiI6Ii91dFRyZlNHVXFnM2R2Mmx0cVJIQUE9PSIsInZhbHVlIjoiNzhpTmk0Q3ZGQzhzWmY2TWRHMTFTQT09IiwibWFjIjoiNWNkMGJiNTVhZDdkNTUzYmM1NWE2MDgwYWIwOWI3ZGZjMmQ4YjFhZGJiN2ZhZDgzZmYxNGZmNjA3OWQ1ZDlhMyIsInRhZyI6IiJ9',
      'supports_onvif' => true,
      'supports_rtsp' => true,
      'is_enabled' => true,
      'recording_mode' => 'motion',
      'recording_profile_index' => 1,
      'recording_retention_days' => 30,
      'motion_sensitivity' => 2,
      'recording_motion_pre_roll_seconds' => 4,
      'recording_motion_post_trigger_seconds' => 20,
      'recording_motion_area' => 
      array (
        'x' => 0,
        'y' => 0,
        'width' => 100,
        'height' => 100,
      ),
      'recording_motion_mask' => 
      array (
        'version' => 1,
        'grid_width' => 160,
        'grid_height' => 90,
        'selected_pixels' => 14400,
        'runs' => 
        array (
          0 => 
          array (
            0 => 0,
            1 => 14399,
          ),
        ),
      ),
      'recording_last_motion_at' => '2026-04-11 09:00:08',
      'recording_last_recorded_at' => '2026-04-11 09:00:28',
      'last_seen_at' => '2026-04-11 09:00:20',
      'metadata' => 
      array (
        'onvif' => 
        array (
          'service_url' => 'http://192.168.1.67:2020/onvif/device_service',
          'response_time_ms' => 24,
          'firmware_version' => '1.0.17 Build 240806 Rel.39518n',
          'hardware_id' => '5.0',
          'http_status' => 200,
          'authenticated' => true,
          'response_excerpt' => 'urn:uuid:52058524-879a-46c3-a3c6-b428649e5af3http://www.w3.org/2005/08/addressing/anonymousttlhttp://192.168.1.67:2020/onvif/device_servicehttp://www.onvif.org/ver10/device/wsdl/GetDeviceInformationtp-linkTapo C2001.0.17 Build 240806 Rel.39...',
          'last_verified_at' => '2026-04-07T19:10:13+00:00',
          'media_service_url' => 'http://192.168.1.67:2020/onvif/service',
          'last_rtsp_sync_at' => '2026-04-07T19:10:29+00:00',
        ),
        'rtsp_profiles' => 
        array (
          0 => 
          array (
            'token' => 'profile_1',
            'name' => 'mainStream',
            'encoding' => 'H264',
            'resolution' => '1920x1080',
            'uri' => 'rtsp://192.168.1.67:554/stream1',
            'path' => '/stream1',
          ),
          1 => 
          array (
            'token' => 'profile_2',
            'name' => 'minorStream',
            'encoding' => 'H264',
            'resolution' => '1280x720',
            'uri' => 'rtsp://192.168.1.67:554/stream2',
            'path' => '/stream2',
            'probe_status' => 'Healthy',
            'probe_checked_at' => '2026-04-11 09:00:16 UTC',
            'probe_message' => 'RTSP connection confirmed from this host.',
            'video_codec' => 'h264',
            'video_resolution' => '1280x720',
            'preview_path' => 'cameras/4/previews/minorstream-2.jpg',
            'preview_generated_at' => '2026-04-11 09:00:16 UTC',
            'preview_message' => 'Snapshot captured successfully.',
            'transport' => 'TCP',
          ),
        ),
        'preview_maintenance' => 
        array (
          'last_attempted_at' => '2026-04-11T09:00:20+00:00',
          'last_profile_index' => 1,
        ),
      ),
      'created_at' => '2026-04-07 19:10:13',
      'updated_at' => '2026-04-11 09:01:09',
    ),
    4 => 
    array (
      'uuid' => '019d695c-2e16-72a8-a5d3-6e0f9e37e563',
      'name' => 'Livingroom',
      'local_ip' => '192.168.1.70',
      'hostname' => NULL,
      'manufacturer' => 'tp-link',
      'model' => 'Tapo C200',
      'serial_number' => 'f4cc9a8b',
      'mac_address' => NULL,
      'http_port' => 2020,
      'onvif_port' => 2020,
      'rtsp_port' => 554,
      'onvif_path' => '/onvif/device_service',
      'rtsp_path' => '/stream2',
      'rtsp_transport' => 'tcp',
      'username' => 'rakelord',
      'password' => 'eyJpdiI6ImNjdDhzb1cyRVBaRWtjRy9TWUpRZkE9PSIsInZhbHVlIjoia1BIQjQ5Y0lqRkRPdGw5eVZPbFdidz09IiwibWFjIjoiOTJjYTU4ZGU1YTJiZjhkMmEyMmY1ZWQ4OTc4ZmQwZGM0MGEyYWU3Y2Q0MmNjNmI2NWI2OWQ0NWU2Zjk4OGQ1YiIsInRhZyI6IiJ9',
      'supports_onvif' => true,
      'supports_rtsp' => true,
      'is_enabled' => true,
      'recording_mode' => 'motion',
      'recording_profile_index' => 1,
      'recording_retention_days' => 30,
      'motion_sensitivity' => 2,
      'recording_motion_pre_roll_seconds' => 4,
      'recording_motion_post_trigger_seconds' => 20,
      'recording_motion_area' => 
      array (
        'x' => 0,
        'y' => 0,
        'width' => 100,
        'height' => 100,
      ),
      'recording_motion_mask' => 
      array (
        'version' => 1,
        'grid_width' => 160,
        'grid_height' => 90,
        'selected_pixels' => 14400,
        'runs' => 
        array (
          0 => 
          array (
            0 => 0,
            1 => 14399,
          ),
        ),
      ),
      'recording_last_motion_at' => '2026-04-11 09:00:24',
      'recording_last_recorded_at' => '2026-04-11 09:00:44',
      'last_seen_at' => '2026-04-11 09:00:26',
      'metadata' => 
      array (
        'onvif' => 
        array (
          'service_url' => 'http://192.168.1.70:2020/onvif/device_service',
          'response_time_ms' => 30,
          'firmware_version' => '1.3.15 Build 240715 Rel.43073n(4555)',
          'hardware_id' => '3.0',
          'http_status' => 200,
          'authenticated' => true,
          'response_excerpt' => 'urn:uuid:12c7f215-8c4a-467e-a342-16ffe40f5029http://www.w3.org/2005/08/addressing/anonymoushttp://192.168.1.70:2020/onvif/device_servicehttp://www.onvif.org/ver10/device/wsdl/GetDeviceInformationtp-linkTapo C2001.3.15 Build 240715 Rel.43073...',
          'last_verified_at' => '2026-04-07T19:12:22+00:00',
          'media_service_url' => 'http://192.168.1.70:2020/onvif/service',
          'last_rtsp_sync_at' => '2026-04-07T19:12:34+00:00',
        ),
        'rtsp_profiles' => 
        array (
          0 => 
          array (
            'token' => 'profile_1',
            'name' => 'mainStream',
            'encoding' => 'H264',
            'resolution' => '1920x1080',
            'uri' => 'rtsp://192.168.1.70:554/stream1',
            'path' => '/stream1',
          ),
          1 => 
          array (
            'token' => 'profile_2',
            'name' => 'minorStream',
            'encoding' => 'H264',
            'resolution' => '640x360',
            'uri' => 'rtsp://192.168.1.70:554/stream2',
            'path' => '/stream2',
            'probe_status' => 'Healthy',
            'probe_checked_at' => '2026-04-11 09:00:20 UTC',
            'probe_message' => 'RTSP connection confirmed from this host.',
            'video_codec' => 'h264',
            'video_resolution' => '640x360',
            'preview_path' => 'cameras/5/previews/minorstream-2.jpg',
            'preview_generated_at' => '2026-04-11 09:00:20 UTC',
            'preview_message' => 'Snapshot captured successfully.',
            'transport' => 'TCP',
          ),
        ),
        'preview_maintenance' => 
        array (
          'last_attempted_at' => '2026-04-11T09:00:26+00:00',
          'last_profile_index' => 1,
        ),
      ),
      'created_at' => '2026-04-07 19:12:22',
      'updated_at' => '2026-04-11 09:01:17',
    ),
    5 => 
    array (
      'uuid' => '019d695d-299c-7038-a24c-6c71a0521ab3',
      'name' => 'Gamingroom',
      'local_ip' => '192.168.1.69',
      'hostname' => NULL,
      'manufacturer' => 'tp-link',
      'model' => 'Tapo C200',
      'serial_number' => '213b9155',
      'mac_address' => NULL,
      'http_port' => 2020,
      'onvif_port' => 2020,
      'rtsp_port' => 554,
      'onvif_path' => '/onvif/device_service',
      'rtsp_path' => '/stream2',
      'rtsp_transport' => 'tcp',
      'username' => 'rekanized',
      'password' => 'eyJpdiI6Im9sRkR6c0U4b0JUM2x0K05zaWo4RWc9PSIsInZhbHVlIjoibmJUOHhHZlNSY0VZYlNIRTNuWEdBdz09IiwibWFjIjoiZGQ0NjIwNGEzZDBiZmVkODNiZWE5MmIzZGJhYWU4YzZkMDE4NDc2NmI2Mzc0YzAxZDVkZGU1ZjUwYzljN2VhZiIsInRhZyI6IiJ9',
      'supports_onvif' => true,
      'supports_rtsp' => true,
      'is_enabled' => true,
      'recording_mode' => 'motion',
      'recording_profile_index' => 1,
      'recording_retention_days' => 30,
      'motion_sensitivity' => 2,
      'recording_motion_pre_roll_seconds' => 4,
      'recording_motion_post_trigger_seconds' => 20,
      'recording_motion_area' => 
      array (
        'x' => 0,
        'y' => 0,
        'width' => 100,
        'height' => 100,
      ),
      'recording_motion_mask' => 
      array (
        'version' => 1,
        'grid_width' => 160,
        'grid_height' => 90,
        'selected_pixels' => 8222,
        'runs' => 
        array (
          0 => 
          array (
            0 => 0,
            1 => 104,
          ),
          1 => 
          array (
            0 => 160,
            1 => 264,
          ),
          2 => 
          array (
            0 => 320,
            1 => 424,
          ),
          3 => 
          array (
            0 => 480,
            1 => 584,
          ),
          4 => 
          array (
            0 => 640,
            1 => 744,
          ),
          5 => 
          array (
            0 => 800,
            1 => 904,
          ),
          6 => 
          array (
            0 => 960,
            1 => 1064,
          ),
          7 => 
          array (
            0 => 1120,
            1 => 1224,
          ),
          8 => 
          array (
            0 => 1280,
            1 => 1384,
          ),
          9 => 
          array (
            0 => 1440,
            1 => 1544,
          ),
          10 => 
          array (
            0 => 1592,
            1 => 1592,
          ),
          11 => 
          array (
            0 => 1600,
            1 => 1704,
          ),
          12 => 
          array (
            0 => 1760,
            1 => 1864,
          ),
          13 => 
          array (
            0 => 1920,
            1 => 2024,
          ),
          14 => 
          array (
            0 => 2080,
            1 => 2184,
          ),
          15 => 
          array (
            0 => 2240,
            1 => 2344,
          ),
          16 => 
          array (
            0 => 2400,
            1 => 2504,
          ),
          17 => 
          array (
            0 => 2557,
            1 => 2664,
          ),
          18 => 
          array (
            0 => 2715,
            1 => 2790,
          ),
          19 => 
          array (
            0 => 2814,
            1 => 2825,
          ),
          20 => 
          array (
            0 => 2874,
            1 => 2948,
          ),
          21 => 
          array (
            0 => 2985,
            1 => 2985,
          ),
          22 => 
          array (
            0 => 3033,
            1 => 3108,
          ),
          23 => 
          array (
            0 => 3192,
            1 => 3267,
          ),
          24 => 
          array (
            0 => 3351,
            1 => 3427,
          ),
          25 => 
          array (
            0 => 3511,
            1 => 3586,
          ),
          26 => 
          array (
            0 => 3671,
            1 => 3746,
          ),
          27 => 
          array (
            0 => 3831,
            1 => 3906,
          ),
          28 => 
          array (
            0 => 3991,
            1 => 4066,
          ),
          29 => 
          array (
            0 => 4151,
            1 => 4226,
          ),
          30 => 
          array (
            0 => 4310,
            1 => 4386,
          ),
          31 => 
          array (
            0 => 4470,
            1 => 4546,
          ),
          32 => 
          array (
            0 => 4628,
            1 => 4706,
          ),
          33 => 
          array (
            0 => 4787,
            1 => 4866,
          ),
          34 => 
          array (
            0 => 4947,
            1 => 5026,
          ),
          35 => 
          array (
            0 => 5107,
            1 => 5186,
          ),
          36 => 
          array (
            0 => 5267,
            1 => 5346,
          ),
          37 => 
          array (
            0 => 5427,
            1 => 5505,
          ),
          38 => 
          array (
            0 => 5586,
            1 => 5665,
          ),
          39 => 
          array (
            0 => 5746,
            1 => 5825,
          ),
          40 => 
          array (
            0 => 5906,
            1 => 5985,
          ),
          41 => 
          array (
            0 => 6066,
            1 => 6145,
          ),
          42 => 
          array (
            0 => 6225,
            1 => 6305,
          ),
          43 => 
          array (
            0 => 6385,
            1 => 6465,
          ),
          44 => 
          array (
            0 => 6545,
            1 => 6625,
          ),
          45 => 
          array (
            0 => 6705,
            1 => 6785,
          ),
          46 => 
          array (
            0 => 6864,
            1 => 6945,
          ),
          47 => 
          array (
            0 => 7024,
            1 => 7104,
          ),
          48 => 
          array (
            0 => 7183,
            1 => 7264,
          ),
          49 => 
          array (
            0 => 7343,
            1 => 7424,
          ),
          50 => 
          array (
            0 => 7502,
            1 => 7584,
          ),
          51 => 
          array (
            0 => 7662,
            1 => 7743,
          ),
          52 => 
          array (
            0 => 7821,
            1 => 7903,
          ),
          53 => 
          array (
            0 => 7981,
            1 => 8063,
          ),
          54 => 
          array (
            0 => 8141,
            1 => 8223,
          ),
          55 => 
          array (
            0 => 8301,
            1 => 8383,
          ),
          56 => 
          array (
            0 => 8462,
            1 => 8543,
          ),
          57 => 
          array (
            0 => 8624,
            1 => 8703,
          ),
          58 => 
          array (
            0 => 8784,
            1 => 8863,
          ),
          59 => 
          array (
            0 => 8945,
            1 => 9023,
          ),
          60 => 
          array (
            0 => 9105,
            1 => 9183,
          ),
          61 => 
          array (
            0 => 9264,
            1 => 9343,
          ),
          62 => 
          array (
            0 => 9424,
            1 => 9503,
          ),
          63 => 
          array (
            0 => 9584,
            1 => 9663,
          ),
          64 => 
          array (
            0 => 9744,
            1 => 9823,
          ),
          65 => 
          array (
            0 => 9903,
            1 => 9983,
          ),
          66 => 
          array (
            0 => 10063,
            1 => 10143,
          ),
          67 => 
          array (
            0 => 10222,
            1 => 10303,
          ),
          68 => 
          array (
            0 => 10382,
            1 => 10463,
          ),
          69 => 
          array (
            0 => 10541,
            1 => 10623,
          ),
          70 => 
          array (
            0 => 10701,
            1 => 10783,
          ),
          71 => 
          array (
            0 => 10860,
            1 => 10943,
          ),
          72 => 
          array (
            0 => 11018,
            1 => 11103,
          ),
          73 => 
          array (
            0 => 11176,
            1 => 11264,
          ),
          74 => 
          array (
            0 => 11335,
            1 => 11424,
          ),
          75 => 
          array (
            0 => 11495,
            1 => 11585,
          ),
          76 => 
          array (
            0 => 11654,
            1 => 11746,
          ),
          77 => 
          array (
            0 => 11814,
            1 => 11906,
          ),
          78 => 
          array (
            0 => 11973,
            1 => 12067,
          ),
          79 => 
          array (
            0 => 12133,
            1 => 12229,
          ),
          80 => 
          array (
            0 => 12292,
            1 => 12392,
          ),
          81 => 
          array (
            0 => 12452,
            1 => 12555,
          ),
          82 => 
          array (
            0 => 12612,
            1 => 12716,
          ),
          83 => 
          array (
            0 => 12771,
            1 => 12878,
          ),
          84 => 
          array (
            0 => 12931,
            1 => 13040,
          ),
          85 => 
          array (
            0 => 13090,
            1 => 13202,
          ),
          86 => 
          array (
            0 => 13249,
            1 => 13364,
          ),
          87 => 
          array (
            0 => 13409,
            1 => 13527,
          ),
          88 => 
          array (
            0 => 13569,
            1 => 13690,
          ),
          89 => 
          array (
            0 => 13728,
            1 => 13850,
          ),
          90 => 
          array (
            0 => 13888,
            1 => 14011,
          ),
          91 => 
          array (
            0 => 14047,
            1 => 14171,
          ),
          92 => 
          array (
            0 => 14207,
            1 => 14332,
          ),
          93 => 
          array (
            0 => 14366,
            1 => 14399,
          ),
        ),
      ),
      'recording_last_motion_at' => '2026-04-11 08:48:25',
      'recording_last_recorded_at' => '2026-04-11 08:48:45',
      'last_seen_at' => '2026-04-11 09:00:26',
      'metadata' => 
      array (
        'onvif' => 
        array (
          'service_url' => 'http://192.168.1.69:2020/onvif/device_service',
          'response_time_ms' => 20,
          'firmware_version' => '1.0.17 Build 240806 Rel.39518n',
          'hardware_id' => '5.0',
          'http_status' => 200,
          'authenticated' => true,
          'response_excerpt' => 'urn:uuid:c554c770-6714-45c4-a727-9d36ab19b747http://www.w3.org/2005/08/addressing/anonymousttlhttp://192.168.1.69:2020/onvif/device_servicehttp://www.onvif.org/ver10/device/wsdl/GetDeviceInformationtp-linkTapo C2001.0.17 Build 240806 Rel.39...',
          'last_verified_at' => '2026-04-07T19:13:26+00:00',
          'media_service_url' => 'http://192.168.1.69:2020/onvif/service',
          'last_rtsp_sync_at' => '2026-04-07T19:13:38+00:00',
        ),
        'rtsp_profiles' => 
        array (
          0 => 
          array (
            'token' => 'profile_1',
            'name' => 'mainStream',
            'encoding' => 'H264',
            'resolution' => '1920x1080',
            'uri' => 'rtsp://192.168.1.69:554/stream1',
            'path' => '/stream1',
          ),
          1 => 
          array (
            'token' => 'profile_2',
            'name' => 'minorStream',
            'encoding' => 'H264',
            'resolution' => '1280x720',
            'uri' => 'rtsp://192.168.1.69:554/stream2',
            'path' => '/stream2',
            'probe_status' => 'Failed',
            'probe_checked_at' => '2026-04-11 09:00:26 UTC',
            'probe_message' => 'rtsp://rekanized:master17@192.168.1.69:554/stream2: Operation not permitted',
            'video_codec' => 'h264',
            'video_resolution' => '1280x720',
            'preview_path' => 'cameras/6/previews/minorstream-2.jpg',
            'preview_generated_at' => '2026-04-11 00:00:26 UTC',
            'preview_message' => 'Snapshot captured successfully.',
            'transport' => 'TCP',
          ),
        ),
        'preview_maintenance' => 
        array (
          'last_attempted_at' => '2026-04-11T09:00:26+00:00',
          'last_profile_index' => 1,
        ),
      ),
      'created_at' => '2026-04-07 19:13:26',
      'updated_at' => '2026-04-11 09:00:26',
    ),
    6 => 
    array (
      'uuid' => '019d695f-7cd1-71ee-86f0-ab4d2ee49fe3',
      'name' => 'Backyard',
      'local_ip' => '192.168.1.65',
      'hostname' => NULL,
      'manufacturer' => 'IMOU',
      'model' => 'IMOU Looc V2',
      'serial_number' => 'unknown',
      'mac_address' => NULL,
      'http_port' => 80,
      'onvif_port' => 80,
      'rtsp_port' => 554,
      'onvif_path' => '/onvif/device_service',
      'rtsp_path' => '/cam/realmonitor?channel=1&subtype=1',
      'rtsp_transport' => 'tcp',
      'username' => 'admin',
      'password' => 'eyJpdiI6IjZqbHV6Q3diQlJ0QVNleG52VDBHTXc9PSIsInZhbHVlIjoiTjJCSnh6SWk0ZUdxZEtRMis0RjdlQT09IiwibWFjIjoiZDQyNDlmNGI0ZGI5OGZlYTUxNDg1MWUzYTZkYTUxZDM5N2RmMzE4YTBiZjE3ZDQxNjNmNjg1MGY2NjFhOTY3NSIsInRhZyI6IiJ9',
      'supports_onvif' => false,
      'supports_rtsp' => true,
      'is_enabled' => true,
      'recording_mode' => 'motion',
      'recording_profile_index' => NULL,
      'recording_retention_days' => 30,
      'motion_sensitivity' => 2,
      'recording_motion_pre_roll_seconds' => 4,
      'recording_motion_post_trigger_seconds' => 20,
      'recording_motion_area' => 
      array (
        'x' => 0,
        'y' => 0,
        'width' => 100,
        'height' => 100,
      ),
      'recording_motion_mask' => 
      array (
        'version' => 1,
        'grid_width' => 160,
        'grid_height' => 90,
        'selected_pixels' => 13527,
        'runs' => 
        array (
          0 => 
          array (
            0 => 0,
            1 => 138,
          ),
          1 => 
          array (
            0 => 151,
            1 => 298,
          ),
          2 => 
          array (
            0 => 311,
            1 => 458,
          ),
          3 => 
          array (
            0 => 471,
            1 => 618,
          ),
          4 => 
          array (
            0 => 631,
            1 => 778,
          ),
          5 => 
          array (
            0 => 791,
            1 => 938,
          ),
          6 => 
          array (
            0 => 951,
            1 => 1098,
          ),
          7 => 
          array (
            0 => 1110,
            1 => 1258,
          ),
          8 => 
          array (
            0 => 1270,
            1 => 1418,
          ),
          9 => 
          array (
            0 => 1430,
            1 => 1578,
          ),
          10 => 
          array (
            0 => 1590,
            1 => 1738,
          ),
          11 => 
          array (
            0 => 1750,
            1 => 1897,
          ),
          12 => 
          array (
            0 => 1910,
            1 => 2057,
          ),
          13 => 
          array (
            0 => 2070,
            1 => 2217,
          ),
          14 => 
          array (
            0 => 2230,
            1 => 2376,
          ),
          15 => 
          array (
            0 => 2389,
            1 => 2535,
          ),
          16 => 
          array (
            0 => 2549,
            1 => 2695,
          ),
          17 => 
          array (
            0 => 2709,
            1 => 2854,
          ),
          18 => 
          array (
            0 => 2869,
            1 => 3014,
          ),
          19 => 
          array (
            0 => 3029,
            1 => 3174,
          ),
          20 => 
          array (
            0 => 3189,
            1 => 3334,
          ),
          21 => 
          array (
            0 => 3349,
            1 => 3493,
          ),
          22 => 
          array (
            0 => 3509,
            1 => 3653,
          ),
          23 => 
          array (
            0 => 3669,
            1 => 3812,
          ),
          24 => 
          array (
            0 => 3829,
            1 => 3972,
          ),
          25 => 
          array (
            0 => 3989,
            1 => 4131,
          ),
          26 => 
          array (
            0 => 4149,
            1 => 4289,
          ),
          27 => 
          array (
            0 => 4291,
            1 => 4291,
          ),
          28 => 
          array (
            0 => 4309,
            1 => 4447,
          ),
          29 => 
          array (
            0 => 4469,
            1 => 4606,
          ),
          30 => 
          array (
            0 => 4629,
            1 => 4766,
          ),
          31 => 
          array (
            0 => 4789,
            1 => 4925,
          ),
          32 => 
          array (
            0 => 4949,
            1 => 5085,
          ),
          33 => 
          array (
            0 => 5109,
            1 => 5245,
          ),
          34 => 
          array (
            0 => 5269,
            1 => 5404,
          ),
          35 => 
          array (
            0 => 5429,
            1 => 5564,
          ),
          36 => 
          array (
            0 => 5589,
            1 => 5724,
          ),
          37 => 
          array (
            0 => 5749,
            1 => 5884,
          ),
          38 => 
          array (
            0 => 5909,
            1 => 6045,
          ),
          39 => 
          array (
            0 => 6069,
            1 => 6205,
          ),
          40 => 
          array (
            0 => 6229,
            1 => 6366,
          ),
          41 => 
          array (
            0 => 6388,
            1 => 6526,
          ),
          42 => 
          array (
            0 => 6548,
            1 => 6686,
          ),
          43 => 
          array (
            0 => 6708,
            1 => 6846,
          ),
          44 => 
          array (
            0 => 6868,
            1 => 7006,
          ),
          45 => 
          array (
            0 => 7028,
            1 => 7166,
          ),
          46 => 
          array (
            0 => 7187,
            1 => 7327,
          ),
          47 => 
          array (
            0 => 7347,
            1 => 7487,
          ),
          48 => 
          array (
            0 => 7506,
            1 => 7647,
          ),
          49 => 
          array (
            0 => 7666,
            1 => 7807,
          ),
          50 => 
          array (
            0 => 7825,
            1 => 7968,
          ),
          51 => 
          array (
            0 => 7984,
            1 => 8128,
          ),
          52 => 
          array (
            0 => 8144,
            1 => 8290,
          ),
          53 => 
          array (
            0 => 8302,
            1 => 8452,
          ),
          54 => 
          array (
            0 => 8458,
            1 => 14399,
          ),
        ),
      ),
      'recording_last_motion_at' => '2026-04-11 08:16:52',
      'recording_last_recorded_at' => '2026-04-11 08:17:12',
      'last_seen_at' => '2026-04-11 09:00:29',
      'metadata' => 
      array (
        'rtsp_profiles' => 
        array (
          0 => 
          array (
            'name' => 'Saved endpoint',
            'token' => 'manual',
            'uri' => 'rtsp://192.168.1.65:554/cam/realmonitor?channel=1&subtype=1',
            'path' => '/cam/realmonitor?channel=1&subtype=1',
            'transport' => 'TCP',
            'source' => 'manual',
            'probe_status' => 'Healthy',
            'probe_checked_at' => '2026-04-11 09:00:27 UTC',
            'probe_message' => 'RTSP connection confirmed from this host.',
            'video_codec' => 'h264',
            'video_resolution' => '640x480',
            'preview_path' => 'cameras/7/previews/saved-endpoint-1.jpg',
            'preview_generated_at' => '2026-04-11 09:00:27 UTC',
            'preview_message' => 'Snapshot captured successfully.',
          ),
        ),
        'preview_maintenance' => 
        array (
          'last_attempted_at' => '2026-04-11T09:00:29+00:00',
          'last_profile_index' => 0,
        ),
      ),
      'created_at' => '2026-04-07 19:15:58',
      'updated_at' => '2026-04-11 09:00:29',
    ),
    7 => 
    array (
      'uuid' => '019d6960-685d-730d-b713-2f267fb50ddd',
      'name' => 'Garage',
      'local_ip' => '192.168.1.72',
      'hostname' => NULL,
      'manufacturer' => 'tp-link',
      'model' => 'Tapo C200',
      'serial_number' => '7461d507',
      'mac_address' => NULL,
      'http_port' => 2020,
      'onvif_port' => 2020,
      'rtsp_port' => 554,
      'onvif_path' => '/onvif/device_service',
      'rtsp_path' => '/stream2',
      'rtsp_transport' => 'tcp',
      'username' => 'rakelord',
      'password' => 'eyJpdiI6ImhLc1ZkVTgrTWhyTi9LWTJETm5mVWc9PSIsInZhbHVlIjoieEYvRnV5cUMxUDN5OGFTY3hIdWY3QT09IiwibWFjIjoiMzFmZDYyZWY3Nzg2Zjg0Y2QyNjNlOTUwZDdmMmViOTRlNDBhNGVjN2RlN2U3MTMzODVjZjYwMzc1MjEwNGQ0YSIsInRhZyI6IiJ9',
      'supports_onvif' => true,
      'supports_rtsp' => true,
      'is_enabled' => true,
      'recording_mode' => 'motion',
      'recording_profile_index' => 1,
      'recording_retention_days' => 30,
      'motion_sensitivity' => 5,
      'recording_motion_pre_roll_seconds' => 4,
      'recording_motion_post_trigger_seconds' => 20,
      'recording_motion_area' => 
      array (
        'x' => 0,
        'y' => 0,
        'width' => 100,
        'height' => 100,
      ),
      'recording_motion_mask' => 
      array (
        'version' => 1,
        'grid_width' => 160,
        'grid_height' => 90,
        'selected_pixels' => 14400,
        'runs' => 
        array (
          0 => 
          array (
            0 => 0,
            1 => 14399,
          ),
        ),
      ),
      'recording_last_motion_at' => '2026-04-11 08:11:09',
      'recording_last_recorded_at' => '2026-04-11 08:11:29',
      'last_seen_at' => '2026-04-11 09:00:34',
      'metadata' => 
      array (
        'onvif' => 
        array (
          'service_url' => 'http://192.168.1.72:2020/onvif/device_service',
          'response_time_ms' => 52,
          'firmware_version' => '1.3.3 Build 251119 Rel.34740n',
          'hardware_id' => '5.0',
          'http_status' => 200,
          'authenticated' => true,
          'response_excerpt' => 'urn:uuid:43ba32b8-8c08-4d67-8306-c32b74b797e5http://www.w3.org/2005/08/addressing/anonymoushttp://192.168.1.72:2020/onvif/device_servicehttp://www.onvif.org/ver10/device/wsdl/GetDeviceInformationtp-linkTapo C2001.3.3 Build 251119 Rel.34740n...',
          'last_verified_at' => '2026-04-07T19:16:59+00:00',
          'media_service_url' => 'http://192.168.1.72:2020/onvif/service',
          'last_rtsp_sync_at' => '2026-04-07T19:17:09+00:00',
        ),
        'rtsp_profiles' => 
        array (
          0 => 
          array (
            'token' => 'profile_1',
            'name' => 'mainStream',
            'encoding' => 'H264',
            'resolution' => '1920x1080',
            'uri' => 'rtsp://192.168.1.72:554/stream1',
            'path' => '/stream1',
          ),
          1 => 
          array (
            'token' => 'profile_2',
            'name' => 'minorStream',
            'encoding' => 'H264',
            'resolution' => '1280x720',
            'uri' => 'rtsp://192.168.1.72:554/stream2',
            'path' => '/stream2',
            'probe_status' => 'Healthy',
            'probe_checked_at' => '2026-04-11 09:00:30 UTC',
            'probe_message' => 'RTSP connection confirmed from this host.',
            'video_codec' => 'h264',
            'video_resolution' => '1280x720',
            'preview_path' => 'cameras/8/previews/minorstream-2.jpg',
            'preview_generated_at' => '2026-04-11 09:00:30 UTC',
            'preview_message' => 'Snapshot captured successfully.',
            'transport' => 'TCP',
          ),
          2 => 
          array (
            'token' => 'profile_3',
            'name' => 'jpegStream',
            'encoding' => 'JPEG',
            'resolution' => '640x360',
            'uri' => 'rtsp://192.168.1.72:554/stream8',
            'path' => '/stream8',
          ),
        ),
        'preview_maintenance' => 
        array (
          'last_attempted_at' => '2026-04-11T09:00:34+00:00',
          'last_profile_index' => 1,
        ),
      ),
      'created_at' => '2026-04-07 19:16:59',
      'updated_at' => '2026-04-11 09:00:34',
    ),
  ),
  'walls' => 
  array (
    0 => 
    array (
      'name' => 'Primary Wall',
      'slug' => 'primary-wall',
      'description' => 'Default operator wall for configured camera tiles.',
      'grid_columns' => 3,
      'default_tile_orientation' => 'landscape',
      'is_default' => true,
      'is_active' => true,
      'created_at' => '2026-04-07 18:56:19',
      'updated_at' => '2026-04-07 18:56:19',
      'tiles' => 
      array (
        0 => 
        array (
          'position' => 1,
          'orientation' => 'landscape',
          'column_span' => 1,
          'row_span' => 1,
          'is_enabled' => true,
          'created_at' => '2026-04-07 19:17:42',
          'updated_at' => '2026-04-07 19:17:42',
          'camera_uuid' => '019d6953-60f6-70cf-91ee-ef1458471382',
        ),
        1 => 
        array (
          'position' => 2,
          'orientation' => 'landscape',
          'column_span' => 1,
          'row_span' => 1,
          'is_enabled' => true,
          'created_at' => '2026-04-07 19:17:42',
          'updated_at' => '2026-04-07 19:17:42',
          'camera_uuid' => '019d6957-9fd1-7160-957c-f97c18f4f7b2',
        ),
        2 => 
        array (
          'position' => 3,
          'orientation' => 'landscape',
          'column_span' => 1,
          'row_span' => 1,
          'is_enabled' => true,
          'created_at' => '2026-04-07 19:17:42',
          'updated_at' => '2026-04-07 19:17:42',
          'camera_uuid' => '019d695a-37dd-738b-bbcd-dec29822d7e6',
        ),
        3 => 
        array (
          'position' => 4,
          'orientation' => 'landscape',
          'column_span' => 1,
          'row_span' => 1,
          'is_enabled' => true,
          'created_at' => '2026-04-07 19:17:42',
          'updated_at' => '2026-04-07 19:17:42',
          'camera_uuid' => '019d6951-b248-71cb-a3b1-5ac917d5c731',
        ),
        4 => 
        array (
          'position' => 5,
          'orientation' => 'landscape',
          'column_span' => 1,
          'row_span' => 1,
          'is_enabled' => true,
          'created_at' => '2026-04-07 19:17:42',
          'updated_at' => '2026-04-07 19:17:42',
          'camera_uuid' => '019d695c-2e16-72a8-a5d3-6e0f9e37e563',
        ),
        5 => 
        array (
          'position' => 6,
          'orientation' => 'landscape',
          'column_span' => 1,
          'row_span' => 1,
          'is_enabled' => true,
          'created_at' => '2026-04-07 19:17:42',
          'updated_at' => '2026-04-07 19:17:42',
          'camera_uuid' => '019d695d-299c-7038-a24c-6c71a0521ab3',
        ),
        6 => 
        array (
          'position' => 7,
          'orientation' => 'landscape',
          'column_span' => 1,
          'row_span' => 1,
          'is_enabled' => true,
          'created_at' => '2026-04-07 19:17:42',
          'updated_at' => '2026-04-07 19:17:42',
          'camera_uuid' => '019d695f-7cd1-71ee-86f0-ab4d2ee49fe3',
        ),
        7 => 
        array (
          'position' => 8,
          'orientation' => 'landscape',
          'column_span' => 1,
          'row_span' => 1,
          'is_enabled' => true,
          'created_at' => '2026-04-07 19:17:42',
          'updated_at' => '2026-04-07 19:17:42',
          'camera_uuid' => '019d6960-685d-730d-b713-2f267fb50ddd',
        ),
      ),
    ),
  ),
);
