# How Current Recap uses Meta's models

This file answers one question: **which of Meta's models does the app use, where, and how?** It is written to be checked against the code, so every row names the file and function.

All cloud models go through the **Meta Model API** (`https://api.meta.ai/v1`, one key, `Authorization: Bearer`). Set the key once in `config.local.php`:

```php
'META_AI_KEY' => 'your key from dev.meta.ai',
```

With no key the app still works: keyword models in `app/engage.php` do the tagging and every Meta-model feature below is simply hidden or skipped. `https://YOURSITE/health.php` shows under `meta_models` which of them are active on your server.

## At a glance

| Model | Used? | What it does in the app | Code |
|---|---|---|---|
| **Muse Spark 1.3** | Yes | Tags shared posts (category, brands, products, tone, a one-line "why it is trending"); writes the ad line in the offer dialog | `app/meta_ai.php` `ai_tag_post`, `ai_ad_message` |
| **Muse Wonder 1.0** | Yes | Writes the 2–3 sentence trend briefing at the top of Bid ads | `app/meta_ai.php` `ai_briefing` |
| **Muse Image** | Yes | "Create ad picture" button: a mock-up banner for the advertiser's offer, shown next to the sponsored post | `app/meta_models.php` `ai_image_generate`, `ai_ad_mockup` |
| **Muse Voice Transcribe** | Yes, needs `ffmpeg` | Transcribes the speech in a shared video; the words feed the tagging model and appear in the post details | `app/meta_models.php` `ai_video_transcript`, `ai_transcribe_wav` |
| **SAM 3.1** | Yes | Finds the products Muse Spark named inside the post picture and draws labelled boxes in the post details | `app/meta_models.php` `ai_sam_find`, `ai_find_products` |
| **Muse Glimmer** (open weights) | Yes, if you host it | Same tagging job as Spark, but on your own server, so post text never leaves it | `app/meta_ai.php` `ai_chat($local = true)` |
| **Muse Spark Open** (open weights) | Yes, if you host it | Same as above (larger model) | same |
| **Muse Code** | **No** (see below) | A coding agent for developers, not a model the running app can call | – |

## How each one is used

### Muse Spark 1.3 — tagging and ad lines
* **Endpoint:** `POST /v1/chat/completions`, model `muse-spark-1.3` (`META_AI_TEXT_MODEL`). Sent with `max_completion_tokens` and `reasoning_effort: "low"`, and temperature left at its default, as Meta's Chat Completions page advises (Spark always reasons, so the token budget is generous).
* **Tagging:** after a trend run, the newest posts that people chose to share are sent one at a time. The prompt asks for one JSON object: `category`, `brands`, `products`, `sentiment`, `why`. The result is cleaned (markup stripped, only known categories accepted) and stored in `content_ai`. It adds topics to Trending ("Rising topics") and the "why" line in the post details.
* **Ad line:** in the offer dialog, **Suggest a message with Muse Spark** writes one honest sponsored line (max 140 characters) for the brand and post.
* **Privacy:** only posts whose owner switched on *Share my post stats* (or licensed imports and public Mastodon/Bluesky posts) are ever sent. A private post is never sent (tested in `tests/test_meta_ai.php`). The plain model is used, not the `-contributor` variant, because Meta may train on contributor traffic.
* **Pictures to Spark (optional):** `META_AI_VISION => true` also sends the post picture to the tagging model.

### Muse Wonder 1.0 — advertiser briefing
`ai_briefing` sends the advertiser's brand and keywords plus the numbers for the topics that are rising (topic, how many times normal, posts, creators) and asks for a short, no-hype briefing that uses only those numbers. Model `muse-wonder-1.0` (`META_AI_BRIEF_MODEL`), cached for an hour.

### Muse Image — ad picture mock-ups
In **Bid ads → Send ad offer**, **Create ad picture with Muse Image** calls `POST /v1/images/generations` (model `muse-image-1.0`, `response_format: b64_json`, `output_format: jpeg`). The prompt is built from the brand name and a short summary of the post, and asks for no faces, text or logos. The picture is stored in `storage/mockups/`, previewed in the dialog and, if the advertiser sends the offer, attached to it. If the creator accepts, the picture shows next to the "Sponsored by" line. Each picture is billed by Meta, so there is a per-advertiser daily cap (`META_AI_IMAGE_PER_DAY`, default 20). The picture file is only served to the advertiser, the post's owner, or people who may see the sponsored post (`thumb.php`).

### Muse Voice Transcribe — what is said in a video
For a shared video post that has a playable file (public Mastodon videos have one), `ai_video_transcript`:
1. checks the link is public https (never localhost or a private network) and downloads at most 25 MB;
2. uses `ffmpeg` to cut the first 90 seconds into the mono 16 kHz WAV the model accepts;
3. sends it to `POST /v1/asr/transcribe` (multipart: a JSON `request` part with `model: muse-voice-transcribe-1.0`, `mode: PUSH_TO_TALK`, `audioEncoding: WAV`, plus brand names from the lexicon as `keywords` so they are spelled right, and the `audio` part);
4. stores the transcript and passes it to Muse Spark as "words spoken in the video", so a brand that is only *said* still counts as a topic.

Needs `ffmpeg` on the server (`META_AI_FFMPEG`). Shared hosting without it just skips this step. At most `META_AI_VOICE_MAX` (3) videos per trend run.

### SAM 3.1 — products inside the picture
After Muse Spark names a product (for example "running shoe"), the post picture and that phrase are sent to `POST /v1/responses` with model `sam-3.1` (`META_AI_SAM_MODEL`), `stream: true`, and the picture as an `input_image` data URL. The reply is SAM's special-token text; the app reads the `box` tokens (`x1,y1,x2,y2`), converts them to fractions of the picture and stores them. The post details then draw the boxes with the product label. Up to 2 products per post and `META_AI_SAM_MAX` (5) posts per run. Masks are ignored; only boxes are used.

### Muse Glimmer and Muse Spark Open — open weights on your own server
These are not behind the Meta Model API; you download and serve them yourself (Meta's documentation names SGLang and vLLM as serving runtimes). Run an OpenAI-compatible server, then:

```php
'META_AI_LOCAL_URL'   => 'http://127.0.0.1:8000/v1',
'META_AI_LOCAL_MODEL' => 'the model name your server was started with',
```
When `META_AI_LOCAL_URL` is set, post tagging goes to that server first (post text stays on your machine) and only falls back to the cloud model if it fails and a key exists. Wonder, Image, Voice and SAM always use the cloud API, because Meta offers them there.

### Muse Code — not part of the app
Muse Code is a coding agent that developers run on their own computer (Meta installs it with `curl -fsSL https://dev.meta.ai/install.sh | bash`). A running web app cannot call it, so it is **not** used by Current Recap at run time, and I will not claim otherwise. This code base was written with another coding assistant, not Muse Code. If you want Muse Code in the workflow, the honest way is to open this project in it for development tasks such as adding tests, extending `app/lexicon.php`, or reviewing `app/meta_models.php`.

## What has and has not been tested

* **Tested automatically** (`php tests/test_meta_ai.php`, `php tests/test_live_media.php`): every request is built and every reply parsed against a local stand-in (`tools/mock_meta.php`) that follows the shapes in Meta's documentation: chat completions, the multipart speech request, the streamed SAM reply, the base64 image reply. This proves the app's logic (privacy filter, parsing, storage, fallbacks, caps).
* **Not tested against Meta's live servers.** I had no API key and no access to api.meta.ai. Things worth checking the first time you run with a real key:
  1. the multipart `request` part of the speech call (sent as an `application/json` part);
  2. SAM's box fields (`w`/`h` are read as the source size, with the picture's own size as a fallback). Meta's pricing page spells the model `sam-3-1` while its API page uses `sam-3.1`; the app uses `sam-3.1` and you can change `META_AI_SAM_MODEL`;
  3. the exact model names for the open weights on your server;
  4. `reasoning_effort: low` and `max_completion_tokens` on Spark (documented on the Chat Completions page).
* Failures never break the app: each call is wrapped, errors go to the PHP error log with the prefix `[recap]`, and the keyword models carry on.

## Cost control
Spark and Wonder results are cached; every post is analysed once; there are per-run caps for Voice (3) and SAM (5) and a daily cap for Muse Image. Meta's prices are on dev.meta.ai/products/meta-model-api.
