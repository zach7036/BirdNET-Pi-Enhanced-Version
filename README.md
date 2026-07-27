# BirdNET-Pi Enhanced — website

Source for <https://zach7036.github.io/BirdNET-Pi-Enhanced-Version/>, the site for
[BirdNET-Pi Enhanced Version](https://github.com/zach7036/BirdNET-Pi-Enhanced-Version).

This is the `gh-pages` branch of that repo. It holds only the website — no application
code — so the site can be edited and deployed without touching `main`.

Plain static HTML, no build step. `.nojekyll` tells GitHub Pages to serve the files as-is.

## Editing

Edit the `.html` files and push to `gh-pages`; Pages redeploys within a minute or two.
Shared styling is in `assets/style.css`. The nav and footer are repeated on each page, so a
new page means editing both, plus `sitemap.xml`.

Because the site is served from a project subpath rather than the domain root, links
between pages must stay **relative** (`install.html`, not `/install.html`). The one
exception is `404.html`, which GitHub serves for missing paths at any depth and so needs
absolute `/BirdNET-Pi-Enhanced-Version/...` links.

## Previewing locally

Serving the folder at `/` would hide broken absolute links and make `404.html` untestable,
so preview it under the real prefix instead.
