# BigBrotha brand assets

The logo retains the original blue camera, cyan lens, red recording light, and watch arc. Simplified geometry, a single arc, and stronger spacing keep the mark recognizable at small sizes. The red dot is part of the brand artwork, not an indication of camera or recording status.

| Asset | Use |
| --- | --- |
| `public/img/bigbrotha-logo.svg` | Application header, sign-in, and general artwork at 32 px and larger. Rounded background; scalable 64-unit viewBox. |
| `public/favicon.svg` | Browser tabs and other 16–32 px placements. Optically adjusted 32-unit artwork with flat colors, a larger recording dot, and no lens glint. |
| `public/img/bigbrotha-app-icon.svg` | Mobile export source with an opaque, full-bleed background. The camera artwork fits inside the central 80% safe-area circle for platform masks. |
| `public/favicon.ico` | Matching 16, 32, and 48 px favicon fallback, rendered from `favicon.svg`. |
| `public/apple-touch-icon.png` | 180 px home-screen icon, rendered from `bigbrotha-app-icon.svg`. |
| `public/img/bigbrotha-icon-192.png`, `public/img/bigbrotha-icon-512.png` | Standard manifest icons, rendered from `bigbrotha-logo.svg`. |
| `public/img/bigbrotha-icon-maskable-512.png` | Maskable manifest icon, rendered from `bigbrotha-app-icon.svg`. |

All SVGs are self-contained geometry with accessible titles, no embedded bitmaps, no fonts, and no external resources. Keep the square aspect ratio. Use the full-bleed version for platform masking; do not pre-round its PNG exports. Re-export the corresponding PNG/ICO files after changing an SVG source. Exports are committed static assets and require no runtime dependency or frontend build step.

The application, login, and setup layouts include `layouts.partials.app-icons` for versioned favicon, Apple touch icon, and manifest links. Application logo images are also versioned and have explicit square dimensions. Continue using an empty image `alt` when adjacent text already names the application.

`public/site.webmanifest` provides home-screen metadata and standalone display where supported. Relative start, scope, and icon URLs preserve deployments below a URL prefix. This adds no service worker or offline operation; camera access and authentication still require the running platform. The static manifest uses the BigBrotha product name.

Validation: inspected SVG renderings at 16, 24, 32, 38, 48, 64, and 72 px on light and dark surfaces, plus a circular mobile mask. PNG exports and the ICO directory are checked against their declared dimensions.
