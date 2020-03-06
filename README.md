# EPFL Menus Plug-In

Copyright © 2020 École Polytechnique Fédérale de Lausanne, Switzerland
All Rights Reserved, except as stated in the LICENSE file.

# Summary

This extension stitches menus together using a REST-based
publish/subscribe mechanism, so that multiple WordPress sites appear
to share the same menu.

The [EPFL](https://www.epfl.ch/) is a pretty big place, and Wordpress'
access control is not up to task with respect to its administrative
complexity. The solution we came up with is to apportion pages and
posts of large Web sites into as many WordPress instances as there are
administrative subdivisions to cater to. This is made transparent to
the visitor through a number of tricks and extensions, including this
one.

# Design

The epfl-menus plug-in leverages WordPress' “normal” menu facilities.
Each site is responsible (we say it is **authoritative**) for its own
section of the overall menu tree, which can be edited using the usual
feature under `wp-admin` → Appearance → Menus.

In order to “stitch” those sections of menu into the complete menu,
the epfl-menus plug-in provides a [custom post
type](https://codex.wordpress.org/Post_Types) called
`epfl-external-menu`, which represents another site's menu.
`epfl-external-menu` posts appear in the WordPress admin area, and
they can also be inserted into a menu to represent a “graft point”
where the sub-site's menu is to be automatically inserted at.

## External Menu Objects

Assume a hierarchy of Web sites, e.g.

- www.epfl.ch
- www.epfl.ch/education
- www.epfl.ch/campus
- www.epfl.ch/campus/spiritual-care
- www.epfl.ch/campus/services
- www.epfl.ch/campus/services/camipro

(... and many more, omitted for brevity.)

Let us focus on www.epfl.ch/campus/services for the discussion in this
paragraph. When clicking on the Refresh button under
[`wp-admin`](https://www.epfl.ch/campus/services/wp-admin) →
Appearance → External Menus, the epfl-menus plug-in probes for, and then
creates (or refreshes) the following External Menu objects:

- Primary[fr] @ /
- Primary[en] @ /
- Principal[fr] @ /campus/services/camipro/
- Primary[en] @ /campus/services/camipro/

... and again, many more.

Each object is a “custom post” in WordPress parlance. Unlike a “real”
post or page, it doesn't have a body; but it does have metadata, which
you can look at by clicking on any of the entries in the [External
Menus
screen](https://www.epfl.ch/campus/services/wp-admin/edit.php?post_type=epfl-external-menu). In particular, you will notice that each External Menu has

- a [Polylang](https://polylang.pro/) language,
- [meta fields](https://developer.wordpress.org/plugins/metadata/) such as
  - `epfl-emi-remote-slug`, which
  indicates the function of that menu on the remote site (e.g. `top` for the primary menu);
  - and most importantly, `epfl-emi-remote-contents-json` which contains a cached copy of the remote menu's state.

## Stitching

As seen above, external menu objects can be placed into the menu to
indicate “graft points”. On any site with the `epfl-menus` WordPress
plug-in active, rendering the menu consists of the following steps:

1. **Stitch down**, i.e. replace all external menu objects in the local menu with the contents of the remote menu (as determined from the external menu object's `epfl-emi-remote-contents-json` meta described in the previous paragraph);
1. ** Stich up**, i.e. find our own spot in the root external menu (e.g. the one called “Primary[en] @ /”, when rendering the menu in English), and replace it with the result of the stitch-down operation obtained in the previous step.

## Caching

For the sake of low latency, **the stitching operation doesn't issue
any REST calls.** Instead, it relies on the
`epfl-emi-remote-contents-json` meta fields of all the menus it
stitches from or into; said meta fields therefore act as a cache for
all the external menu data.

## Syncing and Publish/Subscribe

Like all caches, the `epfl-emi-remote-contents-json` meta field will
go stale at some point and will need re-fetching. For this reason,
**each WordPress site** that has epfl-menus enabled **remembers which
other site(s) queried the menu from itself, and will issue a REST
webhook whenever said menu changes.**

💡 The publish/subscribe REST API is actually entirely independent of
the menu REST API and use-case That is, the REST webhook only
propagates the information that *something* changed; it doesn't carry
a menu-related payload. Instead, it is up to the subscriber to issue
another REST request to figure out *what* changed.

## Tree Propagation

Let's go back to the www.epfl.ch/campus/services example, above. Under
the explanations above (in particular the “Stitching” §), here are the
informations that www.epfl.ch/campus/services **does not** remember or
subscribe to:

- the menu contents of its “parent” (www.epfl.ch/campus) or “sibling”
  sites (www.epfl.ch/campus/spiritual-care), except for the site root
  (which it needs for “stitching up”);
- the menu contents of any “grandchildren“ sites; that information is
  encapsulated (hidden) by the “children“ site, which provide a
  “stitched-down” version of their own menus, as well as any children
  sites' menus, on the REST API.

It follows that when a change is made e.g. in the menu of
www.epfl.ch/campus/services/camipro, the following sequence of events
happens:

1. www.epfl.ch/campus/services gets a “ping” through the REST
  subscription webhook, indicating that e.g. “Primary[en] @
  www.epfl.ch/campus/services/camipro” was just updated
2. www.epfl.ch/campus/services reacts by querying the “main” (not the
   pubsub) REST API of www.epfl.ch/campus/services/camipro, and
   memorizes the update
3. www.epfl.ch/campus/services now in turns sends “ping” to its
   subscribers over the REST subscription webhook, which includes www.epfl.ch/campus
4. Steps 1 through 3 continue recursively until the root of the tree is reached
5. At this point, since everyone is subscribed to the root menu (for
   the purpose of executing the “stitch down” operation), a publish/subscribe “ping”
   will now be broadcast to all sites
6. All sites receive the root “ping” and sync their menus, except for
   the ones that already “know” i.e. the branch along which the
   propagation event was circulated (here, www.epfl.ch/campus,
   www.epfl.ch/campus/services and
   www.epfl.ch/campus/services/camipro) thanks to a mechanism
   explained below.
   
### Loop Prevention and Latency during Tree Propagation

To maintain sanity, and complete user-facing requests (e.g. menu
updates effected from the administrative UI) within the relatively
short 30-second time budget afforded by caching layers such as
CloudFlare, the following implementation choices have been made:

- The REST subscription webhook doesn't carry a menu-related payload;
  however, it does contain an **append-only sequence of per-site
  unique identifiers tracing the causality of the message** (similar
  to [`Received:` headers in
  SMTP](https://tools.ietf.org/html/rfc4021#section-2.1.23). This
  allows for sites that receive a duplicate “ping” (e.g.
  www.epfl.ch/campus, www.epfl.ch/campus/services and
  www.epfl.ch/campus/services/camipro at step 6 in the previous
  paragraph), to simply discard it;
- **All “ping” webhook calls are sent asynchronously**, using the
  `RESTClient::POST_JSON_ff` static method (`_ff` meaning “fire and
  forget”).

