# Platform setup guides

One folder per platform with a step-by-step guide, its screenshots and a short video built from them. Read the guide before connecting a platform for the first time.

| Platform                    | Guide                                      | Video                                                | Status                            |
| --------------------------- | ------------------------------------------ | ---------------------------------------------------- | --------------------------------- |
| LinkedIn (personal profile) | [linkedin/README.md](linkedin/README.md)   | [linkedin-setup.mp4](linkedin/linkedin-setup.mp4)    | done (6 screenshots still to add) |
| LinkedIn Company Page       | `linkedin-pages/`                          |                                                      | with `feat/linkedin-pages`        |
| YouTube (channels)          | [youtube/README.md](youtube/README.md)     | [youtube-setup.mp4](youtube/youtube-setup.mp4)       | done (7 screenshots still to add) |
| Facebook (Pages)            | [facebook/README.md](facebook/README.md)   | [facebook-setup.mp4](facebook/facebook-setup.mp4)    | done (screenshots to add)         |
| Instagram (professional)    | [instagram/README.md](instagram/README.md) | [instagram-setup.mp4](instagram/instagram-setup.mp4) | done (screenshots to add)         |
| TikTok                      | [tiktok/README.md](tiktok/README.md)       | [tiktok-setup.mp4](tiktok/tiktok-setup.mp4)          | done (screenshots to add)         |

## Folder layout

```
docs/guides/<platform>/
  README.md                 the guide (copy _template.md)
  steps.txt                 one line per step: image | caption (drives the video)
  images/NN-short-name.png  one screenshot per step, numbered in order
  <platform>-setup.mp4      built by scripts/make-guide-video.ps1
```

## Adding a new platform guide

1. `mkdir docs/guides/<platform>/images` and copy `_template.md` to `docs/guides/<platform>/README.md`.
2. While doing the setup for real, take a screenshot of every screen you click through. Save them as `images/01-….png`, `02-….png` in order.
3. Hide secrets before saving: blur client secrets, API keys, tokens and passwords. Client IDs can stay.
4. Write `steps.txt`: `NN-name.png | N. what to do on this screen`. A step whose screenshot isn't taken yet still gets a line; the video shows it as a text slide.
5. Fill in the guide: one section per step with the screenshot, exact values to type, and the `.env` keys.
6. Build the video:
    ```bash
    powershell -File scripts/make-guide-video.ps1 <platform>
    ```
7. Add the row to the table above and link the guide from the platform's KB doc.

## Video builder

`scripts/make-guide-video.ps1 <platform> [-SecondsPerSlide 5]` reads `steps.txt`, fits each screenshot on a 1920×1080 frame with its caption underneath, and joins them into `<platform>-setup.mp4`. It needs `ffmpeg` (`FFMPEG_PATH` or on the PATH).
