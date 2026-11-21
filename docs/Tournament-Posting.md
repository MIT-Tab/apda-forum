# Tournament posting integration

Tournament directors choose an existing topic, a new topic name, or the non-APDA option when booking a hosted MIT-Tab tournament. The new-topic option publishes an announcement after the main site is ready. MIT-Tab publishes the final results as a bot reply. Black Rod attaches a link when tab cards are available. Its normal MIT-Tab results import carries the posting ID automatically. For manual data entry, VP Operations can copy the posting ID from the MIT-Tab **Forum Post Results** page.

## Configuration

Deploy the forum helpers and approve schema 66 as described in [Automatic Deployment](Automatic-Deployment.md). Configure the integration only after the forum, MIT-Tab, and Black Rod updates are available.

- **WordPress** defines `APDA_TOURNAMENT_BOT_USER_ID` for a dedicated non-admin account and `APDA_TOURNAMENT_FORUM_ID` for the Tournaments subforum. Existing topics must accept replies and belong to the same category. Define `APDA_MITTAB_FORUM_SIGNING_KEY` and `APDA_BLACK_ROD_FORUM_TOKEN` as separate random secrets.
- **MIT-Tab Deploy** sets `APDA_FORUM_URL` to the WordPress site origin, normally `https://apda.online`, and uses the same `APDA_MITTAB_FORUM_SIGNING_KEY` as WordPress. Newly provisioned tournament sites receive only a signed capability for their own booking. Practice sites and non-APDA events receive no forum configuration.
- **Black Rod** sets `APDA_FORUM_URL` and the same `APDA_BLACK_ROD_FORUM_TOKEN` as WordPress. The tab-card URL uses `https://results.apda.online` by default. A different integration environment can set `APDA_RESULTS_ORIGIN` in Black Rod and `APDA_TOURNAMENT_RESULTS_HOST` in WordPress to match.

Use HTTPS and forward the `X-APDA-Tournament-Token` and `X-APDA-Results-Token` headers to WordPress. Keep both headers out of request logging. When rotating the deployment signing key, existing APDA bookings need their scoped capabilities reissued.

## Endpoints

All endpoints use `POST /wp-json/apda-forum/v1/tournaments/{publication_id}/{operation}`, where the publication ID is the booking's 32-character lowercase hexadecimal ID.

| Operation | Credential | JSON |
| --- | --- | --- |
| `announcement` | `X-APDA-Tournament-Token` for a new topic | Empty body. The capability carries the title and MIT-Tab URL. |
| `results` | `X-APDA-Tournament-Token` | `{"body":"Plain-text results"}` |
| `tab-cards` | `X-APDA-Results-Token` | `{"url":"https://results.apda.online/core/tournaments/42#tab-cards"}` |

The two posting endpoints return topic and post IDs, the public URL when available, and the publication status. A repeat returns the existing post. The tab-card update can arrive before or after the results reply, and only modifies the recorded bot-authored reply. Tab-card viewing uses the existing Black Rod sign-in requirement.
