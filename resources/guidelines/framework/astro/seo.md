---
title: SEO
order: 160
summary: Canonical origin, URLs, meta limits, Open Graph, JSON-LD, sitemap and redirects.
---
# Project guidelines - SEO

Search engines and AI assistants read the HTML the build or the server produces. Every rule below is rendered by the base layout or by Astro integrations, never assembled by hand per page. The page tree, the meta texts, the redirects and what the client still has to deliver are kept in the project's sitemap and meta document; the launch steps are in the project's launch checklist.

## URLs and trailing slash

- The canonical origin is set **once**: the `SITE_URL` build variable ([deployment.md](deployment.md#build-arguments)) feeds `site` in `astro.config.mjs`. Nothing else builds absolute URLs.
- `trailingSlash: 'ignore'` and `build.format: 'directory'` are set in `astro.config.mjs`. `'always'` is not used because it breaks the dev server proxy to the backend; with `'ignore'` the site does not redirect the other form itself.
- The canonical tag, `og:url`, the sitemap, internal links and redirect targets all use **one form** of the URL (with or without the trailing slash, chosen once per project), so the other form is never advertised and the canonical tag tells search engines which one counts.
- Slugs are lowercase words of the site's language without diacritics, joined by hyphens. Paths come from the routes module ([content.md](content.md#routes-and-languages)).

## Meta

- Every indexable page has a unique title of **at most 60 characters** and a description of **110-155 characters**, from its content source; a check verifies the lengths.
- Every page has exactly one `h1`, matching the page's topic.
- `<html lang>` carries the page language, `og:locale` matches it and, on a multilingual site, `hreflang` alternates list every language the page exists in.
- Pages that must not be indexed (404, 500, thank-you pages, the admin, and, when the site has them, account, cart, checkout, login and search pages, plus any page the project decides) carry `noindex` through a base layout flag and are left out of the sitemap.

## Canonical and Open Graph

- Every page declares its canonical URL from `site` and the page path; `og:url` equals it.
- Open Graph and Twitter tags: `og:title`, `og:description`, `og:type`, `og:image` and `twitter:card` `summary_large_image`.
- **OG images are 1200x630**, JPEG or PNG (link previews do not show AVIF), one per indexable page or a shared template with the page title. Their source lives in `assets/` and the design file.

## Structured data

- JSON-LD is rendered on the server in the base layout, from the same data the page shows. It never contains a fact that is not visible on the site.
- Globally: the organisation or local business (`Organization`, `LocalBusiness` or a fitting subtype) with name, URL, logo, contact, address and `sameAs` profiles.
- Per page as it applies: `BreadcrumbList`, `Service`, `Product`, `FAQPage` for real FAQ sections, `Article` or `CreativeWork` for references.
- No `AggregateRating` or `Review` markup for reviews the site publishes about itself.
- The identity (name, address, phone, company id) is identical on the site, in JSON-LD, the business profiles and the social profiles.

## Sitemap, robots, `llms.txt` and Markdown

The rules (all bots allowed, sitemap with `lastmod`, `llms.txt`, Markdown versions, the launch gate and exceptions) are the company-wide AI-readiness standards (https://dev.docs.esoul.cz); the project implements them. Astro implementation:

- `@astrojs/sitemap` writes the sitemap at build time with canonical URLs only and without `noindex` pages (a filter built from the routes module's `index: false` records). On a headless site the sitemap also respects `noindex` set in the backend.
- `robots.txt` and `llms.txt` are static endpoints (`src/pages/robots.txt.ts`, `src/pages/llms.txt.ts`) built from the same content as the pages. Markdown versions of pages, where the standard asks for them, are static endpoints too, advertised with `<link rel="alternate" type="text/markdown">`.
- While the site is a protected preview, `robots.txt` disallows everything, every page is `noindex` and no sitemap is built ([deployment.md](deployment.md#preview-protection)); `robots.txt` stays reachable without basic auth.

## Redirects from the old site

- Every URL of the old site that had traffic or links gets a **301** to its closest new page, as listed in the project's sitemap and meta document. Redirects live in `redirects` in `astro.config.mjs` or in the web server configuration, in one place.
- Content removed for good returns **410**, not a redirect to the homepage. Old upload paths (for example `/wp-content/uploads/*`) are handled explicitly.
- Redirect targets use the canonical URL form ([URLs and trailing slash](#urls-and-trailing-slash)), so no chain of two redirects exists.
- After a slug change, the old slug redirects to the new one.

## After launch

Register the site and the sitemap in Google Search Console and Bing Webmaster Tools and verify the analytics. The checklist is in the project's launch document.
