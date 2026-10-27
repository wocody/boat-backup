---
name: Boat Backup & Migration
description: Native WordPress Tools interface for local migration operations.
colors:
  progress-focus: "#2271b1"
  surface: "#fff"
  border: "#c3c4c7"
  divider: "#dcdcde"
  admin-canvas: "#f0f0f1"
  body-text: "#3c434a"
  heading-text: "#1d2327"
  muted-tab-text: "#50575e"
  active-tab-text: "#000"
  primary: "var(--wp-admin-theme-color, #3858e9)"
  primary-hover: "var(--wp-admin-theme-color-darker-10, #2145e6)"
  primary-active: "var(--wp-admin-theme-color-darker-20, #183ad6)"
  table-stripe: "#f6f7f7"
typography:
  headline:
    fontFamily: '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif'
    fontSize: "23px"
    fontWeight: 400
    lineHeight: 1.3
  title:
    fontSize: "1.3em"
    fontWeight: 600
  body:
    fontFamily: '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif'
    fontSize: "13px"
    lineHeight: 1.5
  label:
    fontSize: "14px"
    fontWeight: 600
    lineHeight: 1.71428571
  button:
    fontSize: "13px"
    fontWeight: 500
    lineHeight: 2.92307692
rounded:
  core-button: "2px"
spacing:
  compact: "4px"
  action-gap: "8px"
  progress-gap: "12px"
  content: "16px"
  section: "24px"
components:
  button-primary:
    backgroundColor: "{colors.primary}"
    textColor: "{colors.surface}"
    rounded: "{rounded.core-button}"
    typography: "{typography.button}"
    padding: "0 16px"
  button-primary-hover:
    backgroundColor: "{colors.primary-hover}"
    textColor: "{colors.surface}"
  button-secondary:
    backgroundColor: "transparent"
    textColor: "{colors.primary}"
    rounded: "{rounded.core-button}"
    typography: "{typography.button}"
    padding: "0 16px"
  operation:
    backgroundColor: "{colors.surface}"
    padding: "8px 24px 24px"
  operation-mobile:
    backgroundColor: "{colors.surface}"
    padding: "4px 16px 20px"
  progress:
    height: "16px"
    width: "100%"
  navigation:
    backgroundColor: "{colors.divider}"
    textColor: "{colors.muted-tab-text}"
    typography: "{typography.label}"
    padding: "5px 10px"
  navigation-active:
    backgroundColor: "{colors.admin-canvas}"
    textColor: "{colors.active-tab-text}"
---

# Design System: Boat Backup & Migration

## Overview

**Creative North Star: "WordPress Tools"**

Boat Backup & Migration extends the WordPress Tools administration screen. Its visual authority is the surrounding dashboard: system typography, core buttons and tabs, flat surfaces, compact explanatory text, and visible operational state. It introduces no separate brand palette, imagery, external assets, or font loading.

The plugin supplies only the spacing, bounded width, progress presentation, and responsive backup layout needed for migration work. Core control styling remains owned by WordPress and can vary by installed version and administrator color scheme.

**Key Characteristics:**

- Native WordPress controls and system typography.
- Restrained width and readable explanatory copy.
- Real progress, recoverable operation states, and explicit restore confirmation.
- Responsive, semantic backup records with wrapping actions.

## Colors

### Primary

WordPress owns the theme-variable primary, hover, and active control colors. The plugin's fixed progress/focus accent belongs to progress and its scoped keyboard outline; it does not replace the administrator's chosen core theme. Values in the frontmatter are source values, including core variable fallbacks, rather than a newly selected palette.

### Neutral

The admin canvas, white operation surface, core borders, lighter divider, dark heading/body text, tab text, and alternating table stripe provide the existing dashboard hierarchy. Use the border token around operation state and private-storage separation; use the divider token inside restore confirmation.

**The Native Theme Rule.** Let WordPress own core control colors; reserve plugin-owned accent styling for existing progress and focus treatments.

## Typography

The system font stack comes from WordPress. The sampled local core supplies the headline, title, body, navigation label, and button roles in the frontmatter. These are inherited observations, not values to redeclare globally: other supported WordPress versions may differ. The title role inherits its line height; body paragraph line height differs from the surrounding body's default. Progress percentages use tabular numerals. The private storage path uses the core code treatment.

There is no display face or webfont. Explanatory paragraphs are capped at 72ch. Emphasis uses native strong text and semibold disclosure summaries, without decorative typography.

## Layout

The screen uses the existing dashboard wrapper and a maximum content width of 1000px. Introduction and operation separation use the section spacing token. Panels use 16px top and 24px bottom padding, without separate card backgrounds. Operation state uses the operation component padding and a 1px border. Actions wrap with the action-gap token.

Progress is a flex row aligned at its center, with the progress-gap token, a 600px maximum width, and a percentage of at least 4ch. The first backup table column occupies 38% on desktop; names, paths, and cell content wrap anywhere when necessary.

At a maximum viewport width of 600px, operation padding switches to the mobile component value. Table records stack; rows use 12px 4px padding, cells 4px 8px. Created and Size receive translated visible labels. Column headers remain visually clipped rather than removed from the accessibility tree, and cells retain explicit header associations. Tab inline padding becomes 12px and left spacing 4px.

## Elevation & Depth

The plugin adds no decorative shadows. A white operation surface, thin borders, table striping, and native selected tabs establish grouping. WordPress may provide focus shadows on its controls; preserve them alongside the plugin's scoped keyboard outline. No custom transition or motion scale is defined.

## Shapes

Operation panels and tables have rectangular outlines. Buttons inherit the local core's small corner radius; the plugin does not create its own radius scale. Inputs, checkboxes, progress, and file selection retain native/core geometry. Avoid introducing floating or decorative card treatments.

## Components

### Buttons

Use core primary buttons for Export site, Upload and verify, Restore this site, and completed-operation destinations. Secondary core buttons support pause, resume, cancellation, downloads, and backup actions. Core owns hover, pressed, disabled, and focus styles. The plugin adds a 2px solid progress-focus outline with a 3px offset for focus-visible descendants. The sampled local core uses 40px minimum-height buttons; inherit the installed version rather than hard-coding that observation into runtime styles.

### Inputs / Fields

The upload control is a native required file input with a visible associated label, accepted .wpboat extension, and linked help text. Constrain its width to its container. Restore uses a native required checkbox in a label; resetting the acknowledgement for each new operation prevents carrying agreement into a later restore.

### Navigation

Export, Import, and Backups are core navigation links with hash destinations and one active page marker. Keep the native tab wrapper and active treatment. These are navigation links, not an ARIA tab widget. Switching links hides inactive sections; each section retains a heading association.

### Operation and confirmation

The operation surface groups its heading, polite live status, native progress element, percentage, and available controls. The progress value comes from backend job data. Busy state follows actual running work. Pause/resume/cancel controls appear according to the job phase. Verified restoration reveals the explicit consequence statement, credential acknowledgement, and final restore button within a divided section. Do not represent this state as an ornamental success card.

### Notices and backup table

Errors use a core inline notice with an alert role. JavaScript-disabled and storage failures use native notices. Backup data uses the core wide table and alternating stripes. Each record provides Download backup, Restore, and Delete actions; restore/delete accessible names include the filename. Deletion uses the browser's confirmation dialog. The empty state is a plain table row. The responsive table preserves the same records and action semantics.

## Do's and Don'ts

### Do:

- **Do** inherit WordPress buttons, fields, notices, tabs, and typography.
- **Do** keep real progress and operation status visible together.
- **Do** preserve table header associations when rows stack on small screens.
- **Do** allow translated English and pt_BR labels, filenames, and storage paths to wrap.
- **Do** require the restore acknowledgement and retain clear action labels.

### Don't:

- **Don't** add a separate visual identity, custom fonts, imagery, or remotely loaded assets.
- **Don't** replace backend progress with simulated progress or decorative animation.
- **Don't** hide mobile table headers from assistive technology.
- **Don't** prescribe the sampled core button sizes or colors as permanent plugin overrides.
