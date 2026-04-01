---
description: "Use when editing Blade views, public CSS, public JavaScript, or UI layout code in this project. Enforces the no-build frontend rules: standard CSS only, direct asset linking, and no npm, Vite, Webpack, Tailwind, Bootstrap, Sass, or similar tooling."
name: "No-Build Frontend"
applyTo: "resources/views/**/*.blade.php, public/**/*.css, public/**/*.js"
---

# No-Build Frontend

- Use standard CSS only.
- Do not add Tailwind, Bootstrap, Sass, Less, PostCSS, CSS Modules, or any CSS framework or preprocessor.
- Do not add npm, npx, package.json, node_modules, Vite, Webpack, Parcel, Rollup, or any asset build pipeline.
- If a new asset is needed, place CSS in public/css and JavaScript in public/js, then link it directly from Blade.
- Keep layouts clear and maintainable for live camera views, multi-feed grids, and recording interfaces.