Publish the flatbb plugin `$ARGUMENTS` to www.flatbb.com.

Steps:
1. Run `php flatbb plugin:check $ARGUMENTS`. If there are errors, fix them in `plugins/$ARGUMENTS/plugin.php` following `docs/PLUGIN.md`, then re-run until it passes.
2. If the plugin was modified in this session and its `version` was not bumped, bump the patch version in the manifest.
3. Make sure `plugins/$ARGUMENTS/README.md` exists and describes the plugin; create a short one if missing.
4. Check that the `FLATBB_TOKEN` environment variable is set (`echo $FLATBB_TOKEN | cut -c1-4` on Unix, `echo %FLATBB_TOKEN:~0,4%` on Windows). If it is not, stop and ask the user to create a token at https://www.flatbb.com/settings/developer and set it. Never ask the user to paste the token into the chat.
5. Run `php flatbb plugin:publish $ARGUMENTS --changelog="<one-line summary of this version>"`.
6. Report the result and the marketplace URL.
