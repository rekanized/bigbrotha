Classic Product Direction

Design for high-density operator interfaces: Interfaces must prioritize raw data density and explicit structural boundaries over negative space.

Explicit visual boundaries: Utilize visible borders, standard <fieldset> elements, and distinct table grids to separate content structurally. Avoid borderless or "clean" surface treatments.

Standardized system defaults: Rely on classic OS-level or browser-default styling. Re-use standard inset and outset border treatments for interactive elements to emulate classic graphical user interfaces.

High-contrast color utility: Utilize web-safe hexadecimal palettes. Reserve #0000FF with underlining strictly for unvisited links and #800080 for visited links.

Static state changes: Eliminate CSS transitions and animations. Hover states and layout updates must be instantaneous.

Fixed or fluid-table layouts: Utilize fixed-width containers or standard percentage-based fluid widths. Avoid complex responsive collapsing.

Core Principles & System Thinking

Establish the structural frame first: Define the overall application shell, standard top navigation frames, and sidebars before detailing internal components.

Standardized initial state: Design with standard black text, grey (#C0C0C0) backgrounds, and explicit borders from the outset.

Constrained technical execution: Restrict styling to standard vanilla CSS properties. Utility frameworks are prohibited.

Visual Hierarchy

Explicit sizing and weight: Rely on standard HTML heading hierarchies (H1-H6). Use standard font-weight: bold and text-decoration: underline for emphasis. Do not use soft contrast adjustments for de-emphasis.

Explicit labeling: All data points and form inputs require persistent, visible labels. Do not omit labels or rely on inline placeholders.

Standardized action semantics: Buttons must utilize standard 3D bevel styling. Links must remain inline text with underlines.

Layout and Spacing

Maximize data density: Minimize white space. Pack elements tightly to reduce vertical scrolling and maximize the volume of information visible in a standard viewport.

Standard HTML spacing: Utilize default <br>, <p>, and <hr> margins for vertical flow and distinct horizontal separation.

Viewport utilization: Expand data tables and forms to utilize the full available width of their parent frames.

Typography and Text

System-default typography: Mandate standard system fonts. Use Times New Roman for document text or Arial / Tahoma for application UI controls.

Standardized sizing: Utilize standard point (pt) or pixel (px) sizing formats. Avoid mathematically generated em or rem scales.

Explicit alignment: Left-align standard text and tabular data. Center-align primary headers or prominent structural blocks.

Working with Color

Hexadecimal standard: Define all colors using explicit Hexadecimal (#RRGGBB) values.

Standardized greyscale: Utilize absolute greys (e.g., #FFFFFF, #C0C0C0, #808080, #000000). Do not tint or saturate grey values with color hues.

Stark contrast: Maintain strict contrast for text readability. Use absolute black text on white or standard grey backgrounds.

Creating Depth and Finish

Hard 3D beveling: Emulate depth using standard CSS border styles (border-style: outset for raised elements and buttons; border-style: inset for depressed elements and form inputs).

Eliminate soft shadows: Do not use box-shadow or blur radii. Depth must be communicated strictly through hard border color contrasts representing highlight and shadow edges.

Utilitarian backgrounds: Utilize solid color backgrounds or standard repeating image tiles. Avoid gradients.

Component Finish

Standardized browser controls: Form inputs, selects, and checkboxes must utilize standard browser-rendered styling. Do not apply custom corner radii or layered borders.

Rigid data grids: Tables and live-feeds must utilize explicit border="1" or equivalent vanilla CSS solid borders on all cells. Utilize alternating row background colors for dense data sets.

Explicit helper text: Include necessary instructions and helper text directly within the layout flow. Do not hide operational text behind hover states.