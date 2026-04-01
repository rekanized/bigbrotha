# Project Guidelines

## Build And Dependencies

- This is a no-build Laravel project.
- Never add or use npm, npx, package.json, node_modules, Vite, Webpack, Tailwind, Bootstrap, Sass, Less, PostCSS, Yarn, pnpm, Bun, or any other frontend build toolchain.
- All project dependencies must come through Composer unless the user explicitly asks for an exception.
- Do not introduce compiled asset pipelines, frontend package managers, or build steps.

## Frontend Conventions

- Use server-rendered Blade templates, standard CSS, and plain browser JavaScript only when JavaScript is needed.
- Store direct frontend assets in public, such as public/css and public/js, and link them directly from Blade templates.
- Do not use CSS frameworks, utility frameworks, preprocessors, or component libraries that require a build step.
- Favor semantic HTML, maintainable class names, and structured layouts for operator-facing screens.

## Product Context

- This application is a web app for live ONVIF and RTSP camera feeds.
- Core features include live viewing, multi-feed layouts, recording workflows, and related operational screens.
- Prefer backend and UI designs that support camera management, stream display, recording, playback, and layout organization.

## Working Defaults

- Assume no Node.js tooling is allowed in this repository.
- Before suggesting or creating a new frontend dependency, assume the answer is no unless the user explicitly requests it.
- Keep solutions compatible with a Composer-only, server-rendered Laravel application.