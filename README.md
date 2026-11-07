# RSS Extender

**Convert Atom or RSS with excerpts to RSS feed with full articles using a CSS selector.**

You can use my instance at [https://rssextender.hoa.ro](https://rssextender.hoa.ro)
or host your own.

> Homepage

![](http://i.imgur.com/Atpe7qL.png)

> CSS Selector

![](http://i.imgur.com/iZDo0DQ.png)

## Usage

  1. Provide an ATOM or RSS feed containing only excerpts
  Example: [https://www.orbitale.io/feed.xml](https://www.orbitale.io/feed.xml)
  2. Open an article from the feed entry, and find a CSS selector which contains the article content.
  Example: `.post-content` (there is no selector)
  3. Fully extracted feed is available at [https://rssextender.hoa.ro/?feed=https%3A%2F%2Fwww.orbitale.io%2Ffeed.xml](https://rssextender.hoa.ro/?feed=https%3A%2F%2Fwww.orbitale.io%2Ffeed.xml)
  4. Articles are cached locally until the feed entry last date changed.

## Installation

Requires PHP 8.4 or newer. Get the project and dependencies:
```
git clone git@github.com:ArthurHoaro/rss-extender.git
composer install
```

Expose the `public` folder behind a web server w/ PHP.

-------

With Docker, build the image from this repository.
Example with [nginx-proxy](https://github.com/nginx-proxy/nginx-proxy) as a reverse proxy:

```
docker build -t rss-extender .
docker run -d \
  -p 80 \
  -v ./data:/var/www/rssextender/data \
  -e VIRTUAL_HOST=<domain.tld> \
  -e VIRTUAL_PORT=80 \
  --restart unless-stopped \
  --name rss-extender \
  rss-extender
```

## Blocking URLs

The server never fetches non-public addresses (localhost, private networks, link-local, ...).
To block more, list host names (their subdomains are blocked too) or IPv4 ranges in
`data/blocklist.txt`, one per line, `#` for comments. It is read on every request.

## License

MIT


