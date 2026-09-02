---
name: Clinical Precision
colors:
  surface: '#f7f9ff'
  surface-dim: '#d7dae0'
  surface-bright: '#f7f9ff'
  surface-container-lowest: '#ffffff'
  surface-container-low: '#f1f4fa'
  surface-container: '#ebeef4'
  surface-container-high: '#e5e8ee'
  surface-container-highest: '#dfe3e8'
  on-surface: '#181c20'
  on-surface-variant: '#424654'
  inverse-surface: '#2d3135'
  inverse-on-surface: '#eef1f7'
  outline: '#737785'
  outline-variant: '#c3c6d6'
  surface-tint: '#0856cf'
  primary: '#0041a2'
  on-primary: '#ffffff'
  primary-container: '#0b57d0'
  on-primary-container: '#ced9ff'
  inverse-primary: '#b2c5ff'
  secondary: '#006b5e'
  on-secondary: '#ffffff'
  secondary-container: '#94f0df'
  on-secondary-container: '#006f62'
  tertiary: '#733700'
  on-tertiary: '#ffffff'
  tertiary-container: '#974a00'
  on-tertiary-container: '#ffd1b4'
  error: '#ba1a1a'
  on-error: '#ffffff'
  error-container: '#ffdad6'
  on-error-container: '#93000a'
  primary-fixed: '#dae2ff'
  primary-fixed-dim: '#b2c5ff'
  on-primary-fixed: '#001847'
  on-primary-fixed-variant: '#0040a1'
  secondary-fixed: '#97f3e2'
  secondary-fixed-dim: '#7ad7c6'
  on-secondary-fixed: '#00201b'
  on-secondary-fixed-variant: '#005047'
  tertiary-fixed: '#ffdcc6'
  tertiary-fixed-dim: '#ffb786'
  on-tertiary-fixed: '#311300'
  on-tertiary-fixed-variant: '#723600'
  background: '#f7f9ff'
  on-background: '#181c20'
  surface-variant: '#dfe3e8'
typography:
  display-lg:
    fontFamily: Inter
    fontSize: 36px
    fontWeight: '700'
    lineHeight: 44px
    letterSpacing: -0.02em
  headline-lg:
    fontFamily: Inter
    fontSize: 28px
    fontWeight: '600'
    lineHeight: 36px
  headline-md:
    fontFamily: Inter
    fontSize: 22px
    fontWeight: '600'
    lineHeight: 30px
  headline-sm:
    fontFamily: Inter
    fontSize: 18px
    fontWeight: '600'
    lineHeight: 26px
  body-lg:
    fontFamily: Inter
    fontSize: 16px
    fontWeight: '400'
    lineHeight: 24px
  body-md:
    fontFamily: Inter
    fontSize: 14px
    fontWeight: '400'
    lineHeight: 20px
  body-sm:
    fontFamily: Inter
    fontSize: 13px
    fontWeight: '400'
    lineHeight: 18px
  label-md:
    fontFamily: Inter
    fontSize: 12px
    fontWeight: '600'
    lineHeight: 16px
    letterSpacing: 0.05em
  code-md:
    fontFamily: Courier Prime
    fontSize: 14px
    fontWeight: '400'
    lineHeight: 20px
rounded:
  sm: 0.25rem
  DEFAULT: 0.5rem
  md: 0.75rem
  lg: 1rem
  xl: 1.5rem
  full: 9999px
spacing:
  base: 4px
  xs: 4px
  sm: 8px
  md: 16px
  lg: 24px
  xl: 32px
  gutter: 16px
  margin-mobile: 16px
  margin-desktop: 32px
---

## Brand & Style

This design system is engineered for high-stakes healthcare environments where clarity, speed, and trust are paramount. The brand personality is clinical, authoritative, and calm, designed to reduce the cognitive load of medical staff during long shifts.

The design style follows a **Modern Corporate** approach with a focus on **Information Density**. It prioritizes high legibility and systematic organization over decorative elements. By utilizing a "clean-room" aesthetic—plenty of functional white space, crisp borders, and a restricted color palette—the UI remains unobtrusive, allowing critical patient data and pharmacy inventory to take center stage.

## Colors

The palette is anchored by **Medical Blue**, a deep, professional hue that evokes stability and institutional trust. 

- **Primary Blue:** Used for primary actions, navigation headers, and active states.
- **Health Teal:** Reserved for health-positive indicators, wellness modules, and secondary confirmations.
- **Alert Amber:** Used sparingly for warnings and cautionary status changes.
- **Systematic Grays:** A range of cool neutrals is used to create a hierarchy of information, with #F8F9FA as the default background to minimize screen glare.

Functional status colors (Success, Warning, Critical, Information) are strictly mapped to industry-standard semantic expectations to ensure immediate recognition of patient vitals or stock levels.

## Typography

The design system utilizes **Inter** for its exceptional legibility in data-heavy contexts. The typeface features a large x-height, making it ideal for reading patient IDs and medication dosages at a glance.

- **Data Density:** Use `body-md` for standard form inputs and table rows.
- **Hierarchy:** `label-md` should be used for table headers and section overviews to differentiate from interactive content.
- **Mobile Adjustments:** On mobile devices, `display-lg` should be replaced by `headline-lg` to prevent text wrapping in critical dashboard views.
- **Monospacing:** Use monospaced fonts for numerical data that requires vertical alignment, such as heart rates or dosage amounts in comparative tables.

## Layout & Spacing

This design system employs a **12-column fluid grid** for desktop and a **4-column grid** for mobile. 

- **Grid Logic:** Use a 4px baseline shift. All spacing (padding, margins, gutters) must be a multiple of 4.
- **Data Tables:** Tables use a "Compact" density by default, with 8px vertical padding in rows to maximize the number of visible records on screen.
- **Responsive Behavior:** Sidebars are collapsible on desktop to provide more horizontal space for wide medical charts. On mobile, the sidebar transitions into a bottom navigation bar or a hamburger menu.
- **Sectioning:** Content is grouped into "Cards" with 24px internal padding, separated by 16px margins to maintain distinct visual clusters.

## Elevation & Depth

To maintain a clean, clinical feel, the design system avoids heavy shadows and skeuomorphism. Depth is communicated primarily through **Tonal Layers** and **Subtle Outlines**.

1.  **Level 0 (Background):** `#F8F9FA`. The foundation layer.
2.  **Level 1 (Surface):** `#FFFFFF`. The standard container for content cards and tables. It uses a 1px border of `#E0E0E0` instead of a shadow.
3.  **Level 2 (Popovers/Modals):** These use a highly diffused, 10% opacity shadow (`0px 4px 20px rgba(0,0,0,0.08)`) to lift them above the work surface without creating visual noise.
4.  **Active State:** Interactive elements like clicked buttons or focused inputs utilize a 2px primary blue halo rather than a depth change.

## Shapes

The design system uses a **Rounded** shape language to soften the clinical aesthetic and make the software feel approachable and modern.

- **Components:** Standard buttons, input fields, and cards utilize the `rounded-md` (0.5rem/8px) setting. 
- **Status Badges:** Small indicators and chips use `rounded-xl` to create a pill-shaped appearance, making them easily distinguishable from actionable buttons.
- **Selection Indicators:** Focus rings and selection highlights follow the parent element's border radius exactly.

## Components

### Tables
Tables are the core of this system. Headers must be sticky. Alternate row striping (`#F8F9FA`) is mandatory for scannability. 

### Status Badges
Badges use high-contrast text on a low-saturation background of the same hue (e.g., Critical uses Red-600 text on Red-50 background). This ensures the "Abnormal" or "Emergency" statuses are visible without overwhelming the user.

### Buttons
- **Primary:** Solid Medical Blue with white text.
- **Secondary:** Medical Blue outline with transparent background.
- **Ghost:** No background/border; only primary color text. Used for less frequent actions like "Add Note."

### Form Fields
Inputs must include clear, persistent labels above the field. Error states must include both a red border and a descriptive icon for accessibility.

### Data Visualization
Charts should use the Primary Blue and Secondary Teal. Avoid using Red or Amber in standard charts unless they represent actual critical thresholds or alerts.

### Medication Cards
Specific cards for pharmacy management should include "Dosage," "Stock Status," and "Expiry" as the primary metadata points, highlighted with the label-md typography style.