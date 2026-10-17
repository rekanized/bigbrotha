// MediaMTX v1.21.1, MIT; see mediamtx.LICENSE.txt.
// Local patches: shared codec probes and explicit, attempt-scoped WHEP teardown.
"use strict";

/**
 * @callback OnError
 * @param {string} err - error.
 */

/**
 * @callback OnTrack
 * @param {RTCTrackEvent} evt - track event.
 */

/**
 * @callback OnDataChannel
 * @param {RTCDataChannelEvent} evt - data channel event.
 */

/**
 * @typedef Conf
 * @type {object}
 * @property {string} url - absolute URL of the WHEP endpoint.
 * @property {string} user - username.
 * @property {string} pass - password.
 * @property {string} token - token.
 * @property {OnError} onError - called when there's an error.
 * @property {OnTrack} onTrack - called when there's a track available.
 * @property {OnDataChannel} onDataChannel - called when there's a data channel available.
 */

/** WebRTC/WHEP reader. */
class MediaMTXWebRTCReader {
  static #RETRY_PAUSE = 2000;
  static #nonAdvertisedCodecsPromise = null;

  #conf;
  #state = "getting_codecs";
  #restartTimeout = null;
  #pc = null;
  #offerData = null;
  #sessionUrl = null;
  #queuedCandidates = [];
  #nonAdvertisedCodecs;
  #generation = 0;
  #abortController = null;

  /**
   * Create a MediaMTXWebRTCReader.
   * @param {Conf} conf - configuration.
   */
  constructor(conf) {
    this.#conf = conf;
    this.#getNonAdvertisedCodecs();
  }

  /**
   * Close the reader and all its resources.
   */
  close() {
    this.#state = "closed";
    this.#disposeConnection();

    if (this.#restartTimeout !== null) {
      clearTimeout(this.#restartTimeout);
      this.#restartTimeout = null;
    }
  }

  #isCurrent(generation) {
    return this.#state === "running" && this.#generation === generation;
  }

  #deleteSession(url) {
    // Keep teardown alive across page navigation; a network failure must not
    // create an unhandled rejection or restart an intentionally closed reader.
    fetch(url, {
      method: "DELETE",
      headers: this.#authHeader(),
      keepalive: true,
    }).catch(() => {});
  }

  #disposeConnection() {
    this.#generation++;
    this.#abortController?.abort();
    this.#abortController = null;

    if (this.#pc !== null) {
      this.#pc.onicecandidate = null;
      this.#pc.onconnectionstatechange = null;
      this.#pc.ontrack = null;
      this.#pc.ondatachannel = null;
      this.#pc.close();
      this.#pc = null;
    }

    if (this.#sessionUrl !== null) {
      this.#deleteSession(this.#sessionUrl);
      this.#sessionUrl = null;
    }

    this.#offerData = null;
    this.#queuedCandidates = [];
  }

  static #supportsNonAdvertisedCodec(codec, fmtp) {
    return new Promise((resolve) => {
      const pc = new RTCPeerConnection({ iceServers: [] });
      const mediaType = "audio";
      let payloadType = "";

      pc.addTransceiver(mediaType, { direction: "recvonly" });
      pc.createOffer()
        .then((offer) => {
          if (offer.sdp === undefined) {
            throw new Error("SDP not present");
          }
          if (offer.sdp.includes(` ${codec}`)) {
            // codec is advertised, there's no need to add it manually
            throw new Error("already present");
          }

          const sections = offer.sdp.split(`m=${mediaType}`);

          const payloadTypes = sections
            .slice(1)
            .map((s) => s.split("\r\n")[0].split(" ").slice(3))
            .reduce((prev, cur) => [...prev, ...cur], []);
          payloadType = this.#reservePayloadType(payloadTypes);

          const lines = sections[1].split("\r\n");
          lines[0] += ` ${payloadType}`;
          lines.splice(lines.length - 1, 0, `a=rtpmap:${payloadType} ${codec}`);
          if (fmtp !== undefined) {
            lines.splice(lines.length - 1, 0, `a=fmtp:${payloadType} ${fmtp}`);
          }
          sections[1] = lines.join("\r\n");
          offer.sdp = sections.join(`m=${mediaType}`);
          return pc.setLocalDescription(offer);
        })
        .then(() =>
          pc.setRemoteDescription(
            new RTCSessionDescription({
              type: "answer",
              sdp:
                "v=0\r\n" +
                "o=- 6539324223450680508 0 IN IP4 0.0.0.0\r\n" +
                "s=-\r\n" +
                "t=0 0\r\n" +
                "a=fingerprint:sha-256 0D:9F:78:15:42:B5:4B:E6:E2:94:3E:5B:37:78:E1:4B:54:59:A3:36:3A:E5:05:EB:27:EE:8F:D2:2D:41:29:25\r\n" +
                `m=${mediaType} 9 UDP/TLS/RTP/SAVPF ${payloadType}\r\n` +
                "c=IN IP4 0.0.0.0\r\n" +
                "a=ice-pwd:7c3bf4770007e7432ee4ea4d697db675\r\n" +
                "a=ice-ufrag:29e036dc\r\n" +
                "a=sendonly\r\n" +
                "a=rtcp-mux\r\n" +
                `a=rtpmap:${payloadType} ${codec}\r\n` +
                (fmtp !== undefined ? `a=fmtp:${payloadType} ${fmtp}\r\n` : ""),
            }),
          ),
        )
        .then(() => {
          resolve(true);
        })
        .catch(() => {
          resolve(false);
        })
        .finally(() => {
          pc.close();
        });
    });
  }

  static #unquoteCredential(v) {
    return JSON.parse(`"${v}"`);
  }

  static #linkToIceServers(links) {
    return links !== null
      ? links.split(", ").map((link) => {
          const m = link.match(
            /^<(.+?)>; rel="ice-server"(; username="(.*?)"; credential="(.*?)"; credential-type="password")?/i,
          );
          const ret = {
            urls: [m[1]],
          };

          if (m[3] !== undefined) {
            ret.username = this.#unquoteCredential(m[3]);
            ret.credential = this.#unquoteCredential(m[4]);
            ret.credentialType = "password";
          }

          return ret;
        })
      : [];
  }

  static #parseOffer(sdp) {
    const ret = {
      iceUfrag: "",
      icePwd: "",
      medias: [],
    };

    for (const line of sdp.split("\r\n")) {
      if (line.startsWith("m=")) {
        ret.medias.push(line.slice("m=".length));
      } else if (ret.iceUfrag === "" && line.startsWith("a=ice-ufrag:")) {
        ret.iceUfrag = line.slice("a=ice-ufrag:".length);
      } else if (ret.icePwd === "" && line.startsWith("a=ice-pwd:")) {
        ret.icePwd = line.slice("a=ice-pwd:".length);
      }
    }

    return ret;
  }

  static #reservePayloadType(payloadTypes) {
    // everything is valid between 30 and 127, except for interval between 64 and 95
    // https://chromium.googlesource.com/external/webrtc/+/refs/heads/master/call/payload_type.h#29
    for (let i = 30; i <= 127; i++) {
      if ((i <= 63 || i >= 96) && !payloadTypes.includes(i.toString())) {
        const pl = i.toString();
        payloadTypes.push(pl);
        return pl;
      }
    }
    throw Error("unable to find a free payload type");
  }

  static #enableStereoPcmau(payloadTypes, section) {
    const lines = section.split("\r\n");

    let payloadType = this.#reservePayloadType(payloadTypes);
    lines[0] += ` ${payloadType}`;
    lines.splice(lines.length - 1, 0, `a=rtpmap:${payloadType} PCMU/8000/2`);
    lines.splice(lines.length - 1, 0, `a=rtcp-fb:${payloadType} transport-cc`);

    payloadType = this.#reservePayloadType(payloadTypes);
    lines[0] += ` ${payloadType}`;
    lines.splice(lines.length - 1, 0, `a=rtpmap:${payloadType} PCMA/8000/2`);
    lines.splice(lines.length - 1, 0, `a=rtcp-fb:${payloadType} transport-cc`);

    return lines.join("\r\n");
  }

  static #enableMultichannelOpus(payloadTypes, section) {
    const lines = section.split("\r\n");

    let payloadType = this.#reservePayloadType(payloadTypes);
    lines[0] += ` ${payloadType}`;
    lines.splice(
      lines.length - 1,
      0,
      `a=rtpmap:${payloadType} multiopus/48000/3`,
    );
    lines.splice(
      lines.length - 1,
      0,
      `a=fmtp:${payloadType} channel_mapping=0,2,1;num_streams=2;coupled_streams=1`,
    );
    lines.splice(lines.length - 1, 0, `a=rtcp-fb:${payloadType} transport-cc`);

    payloadType = this.#reservePayloadType(payloadTypes);
    lines[0] += ` ${payloadType}`;
    lines.splice(
      lines.length - 1,
      0,
      `a=rtpmap:${payloadType} multiopus/48000/4`,
    );
    lines.splice(
      lines.length - 1,
      0,
      `a=fmtp:${payloadType} channel_mapping=0,1,2,3;num_streams=2;coupled_streams=2`,
    );
    lines.splice(lines.length - 1, 0, `a=rtcp-fb:${payloadType} transport-cc`);

    payloadType = this.#reservePayloadType(payloadTypes);
    lines[0] += ` ${payloadType}`;
    lines.splice(
      lines.length - 1,
      0,
      `a=rtpmap:${payloadType} multiopus/48000/5`,
    );
    lines.splice(
      lines.length - 1,
      0,
      `a=fmtp:${payloadType} channel_mapping=0,4,1,2,3;num_streams=3;coupled_streams=2`,
    );
    lines.splice(lines.length - 1, 0, `a=rtcp-fb:${payloadType} transport-cc`);

    payloadType = this.#reservePayloadType(payloadTypes);
    lines[0] += ` ${payloadType}`;
    lines.splice(
      lines.length - 1,
      0,
      `a=rtpmap:${payloadType} multiopus/48000/6`,
    );
    lines.splice(
      lines.length - 1,
      0,
      `a=fmtp:${payloadType} channel_mapping=0,4,1,2,3,5;num_streams=4;coupled_streams=2`,
    );
    lines.splice(lines.length - 1, 0, `a=rtcp-fb:${payloadType} transport-cc`);

    payloadType = this.#reservePayloadType(payloadTypes);
    lines[0] += ` ${payloadType}`;
    lines.splice(
      lines.length - 1,
      0,
      `a=rtpmap:${payloadType} multiopus/48000/7`,
    );
    lines.splice(
      lines.length - 1,
      0,
      `a=fmtp:${payloadType} channel_mapping=0,4,1,2,3,5,6;num_streams=4;coupled_streams=4`,
    );
    lines.splice(lines.length - 1, 0, `a=rtcp-fb:${payloadType} transport-cc`);

    payloadType = this.#reservePayloadType(payloadTypes);
    lines[0] += ` ${payloadType}`;
    lines.splice(
      lines.length - 1,
      0,
      `a=rtpmap:${payloadType} multiopus/48000/8`,
    );
    lines.splice(
      lines.length - 1,
      0,
      `a=fmtp:${payloadType} channel_mapping=0,6,1,4,5,2,3,7;num_streams=5;coupled_streams=4`,
    );
    lines.splice(lines.length - 1, 0, `a=rtcp-fb:${payloadType} transport-cc`);

    return lines.join("\r\n");
  }

  static #enableL16(payloadTypes, section) {
    const lines = section.split("\r\n");

    let payloadType = this.#reservePayloadType(payloadTypes);
    lines[0] += ` ${payloadType}`;
    lines.splice(lines.length - 1, 0, `a=rtpmap:${payloadType} L16/8000/2`);
    lines.splice(lines.length - 1, 0, `a=rtcp-fb:${payloadType} transport-cc`);

    payloadType = this.#reservePayloadType(payloadTypes);
    lines[0] += ` ${payloadType}`;
    lines.splice(lines.length - 1, 0, `a=rtpmap:${payloadType} L16/16000/2`);
    lines.splice(lines.length - 1, 0, `a=rtcp-fb:${payloadType} transport-cc`);

    payloadType = this.#reservePayloadType(payloadTypes);
    lines[0] += ` ${payloadType}`;
    lines.splice(lines.length - 1, 0, `a=rtpmap:${payloadType} L16/48000/2`);
    lines.splice(lines.length - 1, 0, `a=rtcp-fb:${payloadType} transport-cc`);

    return lines.join("\r\n");
  }

  static #enableStereoOpus(section) {
    let opusPayloadFormat = "";
    const lines = section.split("\r\n");

    for (let i = 0; i < lines.length; i++) {
      if (
        lines[i].startsWith("a=rtpmap:") &&
        lines[i].toLowerCase().includes("opus/")
      ) {
        opusPayloadFormat = lines[i].slice("a=rtpmap:".length).split(" ")[0];
        break;
      }
    }

    if (opusPayloadFormat === "") {
      return section;
    }

    for (let i = 0; i < lines.length; i++) {
      if (lines[i].startsWith(`a=fmtp:${opusPayloadFormat} `)) {
        if (!lines[i].includes("stereo")) {
          lines[i] += ";stereo=1";
        }
        if (!lines[i].includes("sprop-stereo")) {
          lines[i] += ";sprop-stereo=1";
        }
      }
    }

    return lines.join("\r\n");
  }

  static #editOffer(sdp, nonAdvertisedCodecs) {
    const sections = sdp.split("m=");

    const payloadTypes = sections
      .slice(1)
      .map((s) => s.split("\r\n")[0].split(" ").slice(3))
      .reduce((prev, cur) => [...prev, ...cur], []);

    for (let i = 1; i < sections.length; i++) {
      if (sections[i].startsWith("audio")) {
        sections[i] = this.#enableStereoOpus(sections[i]);

        if (nonAdvertisedCodecs.includes("pcma/8000/2")) {
          sections[i] = this.#enableStereoPcmau(payloadTypes, sections[i]);
        }
        if (nonAdvertisedCodecs.includes("multiopus/48000/6")) {
          sections[i] = this.#enableMultichannelOpus(payloadTypes, sections[i]);
        }
        if (nonAdvertisedCodecs.includes("L16/48000/2")) {
          sections[i] = this.#enableL16(payloadTypes, sections[i]);
        }

        break;
      }
    }

    return sections.join("m=");
  }

  static #generateSdpFragment(od, candidates) {
    const candidatesByMedia = {};
    for (const candidate of candidates) {
      const mid = candidate.sdpMLineIndex;
      if (candidatesByMedia[mid] === undefined) {
        candidatesByMedia[mid] = [];
      }
      candidatesByMedia[mid].push(candidate);
    }

    let frag = `a=ice-ufrag:${od.iceUfrag}\r\n` + `a=ice-pwd:${od.icePwd}\r\n`;

    let mid = 0;

    for (const media of od.medias) {
      if (candidatesByMedia[mid] !== undefined) {
        frag += `m=${media}\r\n` + `a=mid:${mid}\r\n`;

        for (const candidate of candidatesByMedia[mid]) {
          frag += `a=${candidate.candidate}\r\n`;
        }
      }
      mid++;
    }

    return frag;
  }

  /** @param {string} err */
  #handleError(err, generation = null) {
    if (generation !== null && !this.#isCurrent(generation)) {
      return;
    }

    if (this.#state === "running") {
      this.#state = "restarting";
      this.#disposeConnection();

      this.#restartTimeout = window.setTimeout(
        () => this.#restart(),
        MediaMTXWebRTCReader.#RETRY_PAUSE,
      );

      if (this.#conf.onError !== undefined) {
        this.#conf.onError(`${err}, retrying in some seconds`);
      }
    } else if (this.#state === "getting_codecs") {
      this.#state = "failed";

      if (this.#conf.onError !== undefined) {
        this.#conf.onError(err);
      }
    }
  }

  #restart() {
    this.#restartTimeout = null;
    if (this.#state !== "restarting") {
      return;
    }
    this.#state = "running";
    this.#start();
  }

  #getNonAdvertisedCodecs() {
    MediaMTXWebRTCReader.#nonAdvertisedCodecsPromise ??= Promise.all(
      [
        ["pcma/8000/2"],
        [
          "multiopus/48000/6",
          "channel_mapping=0,4,1,2,3,5;num_streams=4;coupled_streams=2",
        ],
        ["L16/48000/2"],
      ].map((c) =>
        MediaMTXWebRTCReader.#supportsNonAdvertisedCodec(c[0], c[1]).then(
          (r) => (r ? c[0] : false),
        ),
      ),
    )
      .then((c) => c.filter((e) => e !== false))
      .catch((err) => {
        // A transient probe failure must not poison subsequent readers.
        MediaMTXWebRTCReader.#nonAdvertisedCodecsPromise = null;
        throw err;
      });

    MediaMTXWebRTCReader.#nonAdvertisedCodecsPromise
      .then((codecs) => {
        if (this.#state !== "getting_codecs") {
          throw new Error("closed");
        }

        this.#nonAdvertisedCodecs = codecs;
        this.#state = "running";
        this.#start();
      })
      .catch((err) => {
        this.#handleError(err.toString());
      });
  }

  #start() {
    const generation = ++this.#generation;
    this.#abortController = new AbortController();
    this.#requestICEServers()
      .then((iceServers) => this.#setupPeerConnection(iceServers, generation))
      .then((offer) => this.#sendOffer(offer, generation))
      .then((answer) => this.#setAnswer(answer, generation))
      .catch((err) => {
        this.#handleError(err.toString(), generation);
      });
  }

  #authHeader() {
    if (this.#conf.user !== undefined && this.#conf.user !== "") {
      const credentials = btoa(`${this.#conf.user}:${this.#conf.pass}`);
      return { Authorization: `Basic ${credentials}` };
    }
    if (this.#conf.token !== undefined && this.#conf.token !== "") {
      return { Authorization: `Bearer ${this.#conf.token}` };
    }
    return {};
  }

  #requestICEServers() {
    return fetch(this.#conf.url, {
      method: "OPTIONS",
      headers: this.#authHeader(),
      signal: this.#abortController.signal,
    }).then((res) => {
      if (!res.ok) {
        throw new Error(`bad status code ${res.status}`);
      }
      return MediaMTXWebRTCReader.#linkToIceServers(res.headers.get("Link"));
    });
  }

  #setupPeerConnection(iceServers, generation) {
    if (!this.#isCurrent(generation)) {
      throw new Error("closed");
    }

    this.#pc = new RTCPeerConnection({
      iceServers,
      // https://webrtc.org/getting-started/unified-plan-transition-guide
      sdpSemantics: "unified-plan",
    });

    const direction = "recvonly";
    this.#pc.addTransceiver("video", { direction });
    this.#pc.addTransceiver("audio", { direction });

    // using data channels requires creating a data channel locally
    this.#pc.createDataChannel("");

    this.#pc.onicecandidate = (evt) => {
      if (this.#isCurrent(generation)) this.#onLocalCandidate(evt);
    };
    this.#pc.onconnectionstatechange = () => {
      if (this.#isCurrent(generation)) this.#onConnectionState();
    };
    this.#pc.ontrack = (evt) => {
      if (this.#isCurrent(generation)) this.#onTrack(evt);
    };
    this.#pc.ondatachannel = (evt) => {
      if (this.#isCurrent(generation)) this.#onDataChannel(evt);
    };

    return this.#pc.createOffer().then((offer) => {
      if (!this.#isCurrent(generation)) {
        throw new Error("closed");
      }
      offer.sdp = MediaMTXWebRTCReader.#editOffer(
        offer.sdp,
        this.#nonAdvertisedCodecs,
      );
      this.#offerData = MediaMTXWebRTCReader.#parseOffer(offer.sdp);

      return this.#pc.setLocalDescription(offer).then(() => offer.sdp);
    });
  }

  #sendOffer(offer, generation) {
    if (!this.#isCurrent(generation)) {
      throw new Error("closed");
    }

    // Do not abort the POST before its headers arrive: the server may already
    // have allocated a session. A late Location still needs an explicit DELETE.
    return fetch(this.#conf.url, {
      method: "POST",
      headers: {
        ...this.#authHeader(),
        "Content-Type": "application/sdp",
      },
      body: offer,
    }).then((res) => {
      switch (res.status) {
        case 201:
          break;
        case 404:
          throw new Error("stream not found");
        case 400:
          return res.json().then((e) => {
            throw new Error(e.error);
          });
        default:
          throw new Error(`bad status code ${res.status}`);
      }

      const location = res.headers.get("location");
      if (!location) {
        throw new Error("missing WHEP session location");
      }
      const sessionUrl = new URL(
        location,
        this.#conf.url,
      ).toString();
      if (!this.#isCurrent(generation)) {
        this.#deleteSession(sessionUrl);
        throw new Error("closed");
      }
      this.#sessionUrl = sessionUrl;

      return res.text();
    });
  }

  #setAnswer(answer, generation) {
    if (!this.#isCurrent(generation)) {
      throw new Error("closed");
    }

    return this.#pc
      .setRemoteDescription(
        new RTCSessionDescription({
          type: "answer",
          sdp: answer,
        }),
      )
      .then(() => {
        if (!this.#isCurrent(generation)) {
          return;
        }

        if (this.#queuedCandidates.length !== 0) {
          this.#sendLocalCandidates(this.#queuedCandidates);
          this.#queuedCandidates = [];
        }
      });
  }

  #onLocalCandidate(evt) {
    if (this.#state !== "running") {
      return;
    }

    if (evt.candidate !== null) {
      if (this.#sessionUrl === null) {
        this.#queuedCandidates.push(evt.candidate);
      } else {
        this.#sendLocalCandidates([evt.candidate]);
      }
    }
  }

  #sendLocalCandidates(candidates) {
    const generation = this.#generation;
    fetch(this.#sessionUrl, {
      method: "PATCH",
      headers: {
        ...this.#authHeader(),
        "Content-Type": "application/trickle-ice-sdpfrag",
        "If-Match": "*",
      },
      body: MediaMTXWebRTCReader.#generateSdpFragment(
        this.#offerData,
        candidates,
      ),
      signal: this.#abortController.signal,
    })
      .then((res) => {
        switch (res.status) {
          case 204:
            break;
          case 404:
            throw new Error("stream not found");
          default:
            throw new Error(`bad status code ${res.status}`);
        }
      })
      .catch((err) => {
        this.#handleError(err.toString(), generation);
      });
  }

  #onConnectionState() {
    if (this.#state !== "running") {
      return;
    }

    // "closed" can arrive before "failed" and without
    // the close() method being called at all.
    // It happens when the other peer sends a termination
    // message like a DTLS CloseNotify.
    if (
      this.#pc.connectionState === "failed" ||
      this.#pc.connectionState === "closed"
    ) {
      this.#handleError("peer connection closed");
    }
  }

  #onTrack(evt) {
    if (this.#conf.onTrack !== undefined) {
      this.#conf.onTrack(evt);
    }
  }

  #onDataChannel(evt) {
    if (this.#conf.onDataChannel !== undefined) {
      this.#conf.onDataChannel(evt);
    }
  }
}

window.MediaMTXWebRTCReader = MediaMTXWebRTCReader;
