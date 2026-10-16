# Halo Audit 2.0: theme integration (optional)

Updated 2026-10-13.

**The plugin does not need any theme code.** The form, the states, the report layout, the email step and the styles all belong to the plugin. It was run on Twenty Twenty-Five with no theme changes, and on the local Thrivemattic site with the three theme files served to the test browser as they were before the patch below. The files on disk were not changed for that test.

This note covers the one thing a plugin should not do: change the page around it.

## What stays with the page

On `/ai-tools/seo-analysis/` (page 917) and `/ai-tools/competitor-analysis/` (page 937) the shortcode sits in the left half of a two-column block. A sample report sits in the right half. That layout is page content.

The widget fills the place it is put in. In that half column it is about 580 px wide on a desktop, before and after an audit. It does not reach outside its column and it does not hide the sample beside it.

To give the report the full row on those two pages there are two choices:

1. **Keep the optional theme patch** (`theme-integration.patch`, already applied on the local site). It hides the sample column and widens the card while a report is on the page.
2. **Change the page content**: move the shortcode out of the two-column block, or remove the sample column. This needs approval, because it edits the page.

The page's own card heading ("Halo Audit") also repeats the widget heading. The patch hides the widget heading on those two pages. The content-side fix is `title=""` in the shortcode.

## What the widget offers a theme

| Hook | Use |
|---|---|
| `data-state` on `.halo-audit` | `idle`, `invalid`, `loading`, `error`, `preview`, `gate`, `code`, `unlocked` |
| `halo-audit:state` event | Bubbles from `.halo-audit` on every change. `event.detail.state` holds the state name and nothing else |
| `.halo-audit__results[hidden]` | Not hidden exactly while a report is on the page. The current patch reads this |
| `--halo-*` CSS variables | Colours, radius, padding |
| Shortcode `title=""`, `heading_level`, `align` | Heading and width, from page content |

A theme script needs only this to follow the report:

```js
document.addEventListener('halo-audit:state', function (event) {
  var showing = ['preview', 'gate', 'code', 'unlocked'].indexOf(event.detail.state) !== -1;
  event.target.closest('.my-panel').classList.toggle('is-showing-report', showing);
});
```

## The patch on the local site

Files, all outside this repository:

| File | Change |
|---|---|
| `wp-content/themes/generatepress_child/assets/js/tm-seo-analysis.js` | +15 lines: toggle `tmsa-running` on the panel from `.halo-audit__results[hidden]` |
| `wp-content/themes/generatepress_child/assets/js/tm-competitor-analysis.js` | The same with `tmca-running` |
| `wp-content/plugins/tm-blocks/assets/css/tm-blocks.css` | +33 lines in each of the page 917 and page 937 blocks |

The patch still applies and still works with the current plugin. It was not changed in this round.

With the plugin now laying itself out, these patch rules are no longer needed. They do no harm:

- the `max-width: none` and `margin: 0` on `.halo-audit` (the plugin has no fixed width now);
- the stacked form in the half-width card (the plugin stacks the form by its own width);
- the four group cards and the two-column findings (the plugin does both by its own width);
- the `ul.halo-audit__groups` indent fix (the list is no longer a `ul`).

These patch rules still do something:

- the panel toggle (`tmsa-running` / `tmca-running`), which hides the sample column;
- hiding `.halo-audit__title` under the card heading;
- the cream input background and the theme font on the inputs and buttons.

### Deploying the patch, if it is kept

1. Apply `theme-integration.patch` from the WordPress root (the folder that holds `wp-content`): `git apply theme-integration.patch` or `patch -p1 < theme-integration.patch`. It only adds lines. Do not copy the local files over the target's: the local `tm-blocks` folder holds other uncommitted edits.
2. Clear the whole WP Rocket cache, pages and minified files, then any CDN. WP Rocket keeps minified copies under `wp-content/cache/min/` and does not rebuild them when the source changes.
3. If the target serves these files without WP Rocket's minify, bump `TMB_VERSION` in `tm-blocks.php` and the two version strings in the child theme's `functions.php`.
4. If "Remove Unused CSS" is on (it is off locally, so this was not tested), add `halo-audit`, `tmsa-running` and `tmca-running` to its safelist.

## Old theme rules that match nothing now

- `functions.php` line 1300 and `style.css` lines 2237 to 2258 in the child theme name `.seo-analyzer-widget form.seo-analyzer-form button`.
- The `REPORT + MODAL restyle` blocks for pages 917 and 937 in `tm-blocks.css`.

They belong to the 1.x widget and can be removed once 1.x is retired everywhere.

## Open content questions (not decided here)

1. **"No signup" copy.** The full findings and the PDF need a name and an emailed code. These lines say otherwise:
   - Page 917 hero: "Free, in your browser, no signup."
   - Page 917 card note: "Free. No signup. Your report is generated on this page."
   - Page 917 comparison list: "Runs on demand, right here, no signup"
   - Page 917 FAQ: "Yes. No signup, no email gate, no card."
   - Page 937 has the same four lines in its own wording, including "Shareable PDF report, no signup".

   Suggested wording, for approval: "Free. Your score appears on this page. Confirm your email to see every finding and download the PDF." For the FAQ: "Yes. The score is free and needs no account. To see every finding and download the PDF we ask for your name and email and send a 6-digit code. No card."
2. **Page 937.** Competitor comparison was removed in 2.0. The page still promises it throughout: the H1 "see exactly where their page beats yours", "Three fields. One side-by-side verdict.", "Two pages, one keyword, compared across 8 factor categories.", the two-ring sample report, and the FAQ. The widget on it audits one page. The page needs a decision: rewrite it as a single-page audit, redirect it to `/ai-tools/seo-analysis/`, or hold it until a comparison exists.
3. **Other placements of `[seo_analyzer]`:** posts 908 and 1569, page 1023, draft page 3378, and Divi layouts 1012 and 3377. Posts 908 and 1569 serve the widget with no panel around it. Page 1023 holds the shortcode in its stored content, but the served page does not show the widget. Layout 1012 and page 1023 still pass `comparison` and `competitor` attributes, which are ignored.
