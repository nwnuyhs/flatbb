# Report

Members flag a post or a reply for the moderators; the moderators get a queue.

**For members** — every post (not their own) carries a **Report** action. It opens a short page: the post quoted,
a reason to pick (Spam, Harassment or abuse, Off topic, Other — editable in the settings) and an optional note.
One report per member per post; a member may file a limited number per day (setting, default 10).

**For moderators**
- A notification for every new report (can be switched off), linking to the post.
- On a topic page, a post with open reports shows **Reports (n)** in its actions; the link opens the queue for that post.
- The queue at `/reports` (and Admin → Reports for admins) groups reports by post: the topic, who wrote the post, an
  excerpt, and every report with its reason, note and reporter. **Resolve** or **Dismiss** closes them all; the Closed
  tab keeps the history with who handled it, and a closed group can be reopened.

Nothing is changed on the post itself — deleting or editing it stays in the moderator's hands on the post's page.

**Cost**: the topic page runs one extra query for moderators only (none for members); the queue runs six queries
whatever its size. One table, dropped on uninstall.

Requires FlatBB 0.1.23 or later.
