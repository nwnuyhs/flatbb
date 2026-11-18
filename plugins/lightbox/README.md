# Lightbox

Click an image in a post to see it full size on a dark backdrop. When a post has several images, step through them with
the on-screen arrows, the ← / → keys, or a swipe on a touch screen.

- Works on every topic and in the live editor preview — no template change, nothing to configure.
- One overlay is reused for every image; a single document-level click handler means images that arrive with an ajax
  reply also work, with nothing to re-bind.
- The gallery is the images of the post you clicked in: two posts on a page are two separate galleries.
- **Left alone**: avatars and anything outside a post body, and an image that is itself a link to some other page
  (there the link wins). An image linking to another image still opens the lightbox.
- Close with ✕, `Esc`, or a click on the backdrop. A counter (`2 / 5`) and the image's alt text show while it is open.
- Pure front end: no tables, no routes, no settings, and nothing sent anywhere. Uninstalling leaves nothing behind.

Requires FlatBB 0.1.1 or later.
