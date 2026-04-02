Modern Product Direction
Design for a modern operator-facing product: Interfaces should feel current, intentional, and calm rather than dated, generic, or overly decorative
.
Modern does not mean trendy at the expense of usability: Prioritize clarity, fast scanning, and operational confidence first, then layer in polish through typography, color, spacing, and depth
.
Use a consistent visual system: Reuse a defined set of radii, border treatments, shadows, panel styles, and accent colors so the application feels like one product instead of a collection of separate screens
.
Favor clean surfaces and strong grouping: Use cards, panels, section headers, and restrained dividers to create structure, but avoid heavy borders, crowded chrome, or ornamental containers everywhere
.
Use color with purpose: A modern interface should have a clear accent strategy and neutral foundation; reserve strong colors for status, active state, focus, and primary actions instead of coloring everything equally
.
Use motion sparingly and meaningfully: Small transitions for hover, focus, panel reveal, loading, and state changes can make the UI feel modern, but they must remain subtle, fast, and never interfere with operator tasks
.
Responsive polish is required: Modern layouts must work cleanly on desktop and smaller screens, preserving hierarchy, tap targets, spacing, and readability without collapsing into clutter
.

Core Principles & System Thinking
Start with a feature, not a layout: Do not begin by designing the overall "shell" of the app, such as top navigation bars or sidebars
. Instead, start by designing a piece of actual functionality
.
Detail comes later: Begin your designs in grayscale or low-fidelity
. By designing in grayscale, you are forced to use spacing, contrast, and size to establish a strong visual hierarchy before relying on color
.
Limit your choices: Never hand-pick arbitrary values or use an infinite color picker during the design process
. Define constrained systems in advance for typography, colors, margins, padding, and shadows, and only choose from those predefined sets
.
Visual Hierarchy
Not all elements are equal: Do not rely solely on font size to control your hierarchy
. Use font weight (e.g., making primary text bolder) and color contrast (e.g., using a softer, lighter grey for secondary text) to communicate importance
.
Emphasize by de-emphasizing: When a primary element isn't standing out enough, do not make it louder; instead, figure out how to de-emphasize the competing elements, such as giving them a softer background color
.
Labels are a last resort: Whenever possible, present data so that the format speaks for itself, or combine the label with the value
. If you must use a label, heavily de-emphasize it by making it smaller, reducing its contrast, or using a lighter font weight
.
Balance weight and contrast: Icons generally feel "heavier" than text
. To balance an icon next to text, lower the contrast of the icon by giving it a softer color
.
Semantics are secondary for actions: Design buttons based on their importance, not just their semantic meaning (e.g., "delete")
. Primary actions should be solid and obvious, secondary actions should be clear but not prominent (like outline styles), and tertiary actions should be styled unobtrusively, like links
.
Layout and Spacing
Start with too much white space: Give elements more room to breathe than you think is necessary, and then remove space until you are happy with the result, rather than starting cramped and adding space
.
Establish a spacing and sizing system: Build a linear scale using multiples of a sensible base value, such as 16px (e.g., 4px, 8px, 12px, 16px, 24px, 32px, 48px), and use these exact values for sizing and spacing
.
You don't have to fill the whole screen: Limit the width of interfaces to what they actually need to be legible
. For example, a form typically does not need to be wider than 600px
.
Avoid ambiguous spacing: When grouping elements, ensure that the margin below an item is noticeably smaller than the space between distinct groups, making it visually obvious which elements are connected
.
Typography and Text
Establish a hand-crafted type scale: Avoid using mathematically generated em scales, which often result in fractional values and unwanted resizing
. Instead, hand-pick a scale of pixel or rem values (e.g., 12, 14, 16, 18, 20, 24, 30, 36)
.
Baseline, not center: When mixing different font sizes on a single line, align them by their baseline rather than vertically centering them
.
Keep line length in check: Make paragraphs wide enough to fit between 45 and 75 characters per line for the best reading experience
.
Line-height is proportional: Wide paragraphs and small text need taller line-heights (e.g., 1.5 to 2) to aid readability
. Conversely, large headlines should have a much tighter line-height, often as short as 1
.
Align with readability in mind: Left-align the vast majority of your text
. Center alignment should only be used for headlines or very short blocks of text
. Always right-align numbers in tables
.
Working with Color
Ditch hex for HSL: Represent colors using Hue, Saturation, and Lightness (HSL), which allows you to intuitively adjust how a color looks
.
You need more colors than you think: Define a palette of 8-10 shades (from lightest to darkest) for your base colors and greys
.
Greys don't have to be grey: Saturate your greys with a bit of blue to make them feel cool, or yellow/orange to make them feel warm
.
Don't let lightness kill your saturation: As a color gets closer to 0% or 100% lightness, it loses its colorfulness
. Increase the saturation as you move towards the lightest and darkest ends of your scale to prevent them from looking washed out
.
Don't use grey text on colored backgrounds: Reducing opacity or using grey text on a colored background results in a dull, washed-out look
. Instead, hand-pick a new color with the same hue as the background, adjusting the lightness and saturation to lower the contrast
.
Creating Depth and Finish
Emulate a light source: Simulate a light source coming from above
. Raised elements should have a lighter top edge, while inset elements (like form inputs) should have a darker top edge or an inner shadow
.
Use shadows to convey elevation: Use small shadows with a tight blur radius for elements close to the background (like buttons), and larger shadows with a higher blur radius for elements closer to the user (like modals)
. For the most realistic look, combine two shadows: a large, soft one for direct light and a tight, dark one for ambient light
.
Add color with accent borders: Add a dash of visual flair to bland interfaces by applying colorful accent borders across the top of cards, along the side of alert messages, or under active navigation items
.
Decorate your backgrounds: Break up monotony by adding slight gradients or subtle, low-contrast repeating patterns to background sections
.
Component Finish
Keep component styling crisp and current: Prefer modest corner radii, subtle layering, quiet borders, and deliberate hover or focus states over flat, lifeless blocks or overly glossy treatments
.
Make data-heavy screens feel refined: Tables, live-feed grids, diagnostics, and camera controls should use spacing, alignment, and restrained contrast to feel modern without sacrificing density or scan speed
.
Modern dashboards need restraint: Do not fill every gap with badges, icons, colored pills, or helper text; leave room for the most important live data and actions to lead the screen
