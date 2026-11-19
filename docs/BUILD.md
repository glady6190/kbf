# Rebuilding the site stylesheet

Every page loads one stylesheet, `/assets/css/site.css`. It is built with
Tailwind CSS on this PC. Nothing is built on the server.

Rebuild it whenever you add, remove or change Tailwind classes (for example
`bg-slate-800`, `sm:px-6`, `min-w-[10rem]`) in any page or script. If you skip
this, a new class simply has no style on the live site.

## The files

- `tools/tailwindcss-windows-x64.exe` - the Tailwind CLI program (version 3.4.17).
- `tailwind.config.js` - says which files to scan for class names, plus a
  `safelist` for any class that is built in JavaScript from pieces (for example
  `'bg-' + colour`), which the scan cannot see. Write class names out in full
  where you can; otherwise add them to the safelist.
- `assets/css/tailwind.input.css` - the input file (`@tailwind base`,
  `components`, `utilities`).
- `assets/css/site.css` - the built, minified output. Do not edit it by hand.

## Rebuild

Open a terminal in `C:\KBF` and run:

```
tools\tailwindcss-windows-x64.exe -c tailwind.config.js -i assets/css/tailwind.input.css -o assets/css/site.css --minify
```

It takes about a second. Then upload `assets/css/site.css` with the changed
pages. Browsers may keep the old copy for a while; a hard refresh
(Ctrl+F5) shows the new one.

## What is uploaded

Upload `assets/css/site.css`. Do not upload `tools/`, `docs/`,
`tailwind.config.js` or `assets/css/tailwind.input.css`; the site does not
need them. `tools/` is also left out of git.

## File versions (cache-safe links)

Every link from a page to the site's own CSS and JS files ends in `?v=`
and a short code, for example `/js/escape.js?v=2611191430`. A new code
makes every browser fetch fresh copies straight after a deployment, so a
page never runs with an old cached script (the cause of the dashboard's
"KBF.formatDate is not a function" error). The `.htaccess` caching rules
are unchanged.

The code is kept in one place, `build/asset-version.txt`, and
`build/asset-version.php` writes it into every page. Before the commit
that is packaged, open a terminal in `C:\KBF` and run:

```
php build/asset-version.php
```

This makes a new code from the build date and time (YYMMDDHHMM), saves it
in `build/asset-version.txt` and updates the `?v=` of every link (each
page keeps its line endings). To use a code of your choice instead, add
it: `php build/asset-version.php 2611191500`. To write the saved code
again (for example into a new page), use `--keep`. Then commit and make
the deployment zip from that commit. New pages should link files with
`?v=` too; running the script adds it.

The `build/` folder is not uploaded (the zip leaves it out).

## Getting the program again

The version is pinned to Tailwind CSS v3.4.17 (do not use v4; it works
differently). Download the Windows file into `tools\`:

https://github.com/tailwindlabs/tailwindcss/releases/download/v3.4.17/tailwindcss-windows-x64.exe

Its SHA-256 checksum should be
`67f1c5e3f5a03406a7bf5badf5ada09b79f3ae78ec43450c15f7e983068da346`
(listed in `sha256sums.txt` on the same release page). To check it in
PowerShell: `Get-FileHash tools\tailwindcss-windows-x64.exe`.

## Maintenance mode

When a Super Admin switches maintenance mode on (Site settings), visitors
see the back-soon page. Staff still see the whole site because signing in
sets a small browser cookie, `kbf_staff`, which tells the web server to let
them past the back-soon page. The cookie is renewed while the person stays
signed in and is removed when they sign out.

This cookie is only a convenience switch. It holds no secret, so anyone who
sets it by hand can also get past the back-soon page and look at the public
pages. It grants nothing else: every admin page and every action still
checks the real sign-in on the server. So do not rely on maintenance mode to
hide information from the public; use it only to show a friendly page while
work is going on.
