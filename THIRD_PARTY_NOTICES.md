# Third-party notices

BigBrotha's application code is licensed under MIT. Third-party software retains
its own copyrights and licenses; the application license does not replace them.

- **SortableJS 1.15.6**: MIT, Copyright 2019-present, all contributors to Sortable.
  The vendored browser asset keeps its upstream notice. The full license is
  included at `public/js/vendor/sortable.LICENSE.txt`.
- **MediaMTX**: MIT. The image installs the full license from the checksum-verified
  upstream release archive at `/usr/share/doc/mediamtx/LICENSE`.
  The vendored v1.21.1 WebRTC reader at `public/js/vendor/mediamtx-reader.js`
  shares codec capability detection across players; its license is included at
  `public/js/vendor/mediamtx.LICENSE.txt`. Reapply and test this small patch when
  updating the reader alongside the image's MediaMTX version.
- **Composer packages**, including Laravel, Livewire, Socialite, PHP-FFMpeg, and
  the SMB adapter: their license files remain in the installed `vendor/` packages.
  Run `composer licenses` in the development/test image to inspect the locked
  dependency licenses.
- **FFmpeg and Debian runtime packages**: distributed under their respective
  licenses. The Debian FFmpeg build includes GPL-enabled components; its license
  information is available through `ffmpeg -L` and
  `/usr/share/doc/ffmpeg/copyright` in the image. Package source is available from
  Debian's package repositories; Debian's copyright files identify upstream
  source and licensing terms.

Upstream references: [SortableJS](https://github.com/SortableJS/Sortable),
[MediaMTX](https://github.com/bluenviron/mediamtx),
[FFmpeg legal information](https://ffmpeg.org/legal.html), and
[Debian FFmpeg source](https://sources.debian.org/src/ffmpeg/).
