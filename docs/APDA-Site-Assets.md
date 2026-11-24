# APDA site assets

The APDA Forum plugin can serve the custom CSS and JavaScript previously managed in WordPress on apda.online.

| WordPress source | Repository file | Behavior |
| --- | --- | --- |
| BlogHash Additional CSS, post 3726 | `skin/apda-bloghash.css` | BlogHash layout, light and dark mode styles, and responsive forum styling |
| Simple Custom CSS and JS, snippet 48 | `skin/apda-site.css` | Sidebar, search, and load-more overrides |
| Simple Custom CSS and JS, snippet 3567 | `skin/apda-site.css` | Rainbow styling for the two APDA forum profile links |
| Simple Custom CSS and JS, snippet 3843 | `integrations/integration-apda-site-assets.php` | Edit perms and Add perms shortcuts next to topic authors |

The BlogHash CSS is loaded only with the BlogHash stylesheet. Selectors for APDA's home and forum pages retain their WordPress page IDs (5 and 15). The site CSS loads across public WordPress pages, including pages with forum widgets.

**Edit perms** opens the author's WordPress user editor. **Add perms** submits a native WordPress admin action that adds the Subscriber and Private Side Viewer roles and the APDA Members forum group, then opens the user editor. The action requires WordPress capabilities for editing and promoting the user and resolves the group by its `apda-members` slug.

## Switch over

The repository assets are off by default. After deploying the plugin, back up the three Simple Custom CSS and JS entries and the BlogHash Additional CSS. Compare the current WordPress sources with the repository to catch edits made since the export.

In the same change window, disable snippets 48, 3567, and 3843, clear the migrated BlogHash Additional CSS in the Customizer and publish so BlogHash regenerates its stylesheet, and add the following to the site's WordPress configuration before WordPress loads.

```php
define('APDA_REPO_SITE_ASSETS', true);
```

Clear any page caches and check the home page, the forum on desktop and mobile, and the permission shortcuts on a test account. To roll back, remove the constant, re-enable the three entries, and restore and publish the backed-up Additional CSS. Clear page caches again.
