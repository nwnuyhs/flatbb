<?php
/**
 * Admin: categories and tags. List + drawer editing (see admin_ui.php).
 */

function admin_page_categories(): never
{
    $list_url = admin_url('categories');
    if (is_post()) {
        check_csrf();
        $action = post_str('action', 20);
        $id = post_int('id');
        if ($action === 'delete') {
            $c = category_by_id($id);
            if ($c === null) fail(t('Category not found.'));
            $target = category_by_id(post_int('move_to'));
            if ((int)$c['topic_count'] > 0 && ($target === null || (int)$target['id'] === $id)) fail(t('Choose a category to move the topics to.'));
            if ($target !== null) db_update('fb_topics', ['category_id' => (int)$target['id']], 'category_id=?', [$id]);
            db_update('fb_categories', ['parent_id' => 0], 'parent_id=?', [$id]);
            db_delete('fb_categories', 'id=?', [$id]);
            admin_category_icon_unlink($id);
            admin_log('category.delete', '#' . $id);
            request_cache('categories', null, true);
            if ($target !== null) category_refresh_stats((int)$target['id']);
            flash(t('Category deleted.'));
            redirect($list_url);
        }
        $name = post_str('name', 60);
        if ($name === '') fail(t('Name is required.'));
        $slug = slugify(post_str('slug', 60) ?: $name);
        $parent = category_by_id(post_int('parent_id'));
        if ($parent !== null && ((int)$parent['parent_id'] > 0 || (int)$parent['id'] === $id)) $parent = null;
        $data = [
            'name' => $name, 'slug' => $slug, 'description' => post_str('description', 500), 'parent_id' => (int)($parent['id'] ?? 0),
            'sort' => post_int('sort'), 'icon' => admin_category_icon_value($id, post_str('icon', 120)),
            'view_groups' => implode(',', array_map('intval', post_list('view_groups'))),
            'post_groups' => implode(',', array_map('intval', post_list('post_groups'))),
            'is_hidden' => post_int('is_hidden') ? 1 : 0,
        ];
        $data = hook('admin.category_save', $data, ['id' => $id]);
        if (val('SELECT 1 FROM fb_categories WHERE slug=? AND id<>?', [$slug, $id])) fail(t('Slug already used.'));
        $icon_file = upload_files_list('icon_file')[0] ?? null;
        if ($id > 0) db_update('fb_categories', $data, 'id=?', [$id]);
        else $id = db_insert('fb_categories', $data);
        if ($icon_file !== null) {
            try { db_update('fb_categories', ['icon' => upload_site_image('cat_' . $id, $icon_file, ['png', 'jpg', 'gif', 'webp', 'svg'], 524288)], 'id=?', [$id]); } catch (RuntimeException $e) { request_cache('categories', null, true); fail($e->getMessage()); }
        } elseif (!str_contains((string)$data['icon'], '/')) {
            admin_category_icon_unlink($id); // switched to a built-in icon or none: the uploaded image is not needed any more
        }
        request_cache('categories', null, true);
        admin_log('category.save', '#' . $id . ' ' . (string)($data['name'] ?? ''));
        flash(t('Category saved.'));
        redirect($list_url);
    }
    $rows = [];
    foreach (categories() as $c) {
        $access = ((int)$c['is_hidden'] ? '<span class="flag flag-danger">' . t('hidden') . '</span> ' : '') . ($c['view_groups'] !== '' ? '<span class="flag">' . t('restricted view') . '</span> ' : '') . ($c['post_groups'] !== '' ? '<span class="flag">' . t('restricted posting') . '</span>' : '');
        $rows[] = [
            ((int)$c['parent_id'] ? '<span class="muted">— </span>' : '') . '<b>' . h($c['name']) . '</b><br><small class="muted">' . h($c['description']) . '</small>',
            h($c['slug']), (int)$c['topic_count'], (int)$c['sort'], $access ?: '<span class="muted small">' . t('everyone') . '</span>',
            '<div class="row-actions">' . admin_drawer_link(admin_url('categories', ['edit' => $c['id']]), t('Edit')) . admin_row_menu([
                '<a href="' . h(category_url($c)) . '">' . icon('external') . t('View') . '</a>',
                admin_drawer_link(admin_url('categories', ['delete' => $c['id']]), t('Delete'), 'danger', 'trash'),
            ]) . '</div>',
        ];
    }
    $html = admin_table([t('Category'), t('Slug'), t('Topics'), t('Sort'), t('Access'), ''], $rows);
    $drawer = null;
    if (($eid = get_int('edit', -1)) >= 0) {
        $edit = category_by_id($eid) ?? ['id' => 0, 'name' => '', 'slug' => '', 'description' => '', 'parent_id' => 0, 'icon' => '', 'sort' => 10, 'view_groups' => '', 'post_groups' => '', 'is_hidden' => 0];
        $parents = ['0' => t('— none (top level)')];
        foreach (categories() as $c) if ((int)$c['parent_id'] === 0 && (int)$c['id'] !== (int)$edit['id']) $parents[(string)$c['id']] = $c['name'];
        $gv = explode(',', (string)$edit['view_groups']); $gp = explode(',', (string)$edit['post_groups']);
        $vc = $pc = '';
        foreach (groups() as $g) {
            $vc .= '<label class="check"><input type="checkbox" name="view_groups[]" value="' . (int)$g['id'] . '"' . (in_array((string)$g['id'], $gv, true) ? ' checked' : '') . '> ' . h($g['name']) . '</label> ';
            $pc .= '<label class="check"><input type="checkbox" name="post_groups[]" value="' . (int)$g['id'] . '"' . (in_array((string)$g['id'], $gp, true) ? ' checked' : '') . '> ' . h($g['name']) . '</label> ';
        }
        $body = '<form method="post" action="' . h($list_url) . '" enctype="multipart/form-data">' . csrf_field() . '<input type="hidden" name="id" value="' . (int)$edit['id'] . '">'
            . form_row(t('Name'), input('name', (string)$edit['name'], ['required' => true]))
            . form_row(t('Description'), textarea('description', (string)$edit['description'], ['rows' => 2]))
            . '<div class="form-grid">' . form_row(t('Slug'), input('slug', (string)$edit['slug']), t('URL: /c/slug')) . form_row(t('Parent'), select('parent_id', $parents, (string)$edit['parent_id'])) . '</div>' . form_row(t('Icon'), admin_icon_picker((string)($edit['icon'] ?? '')), t('Shown before the name in menus and the category bar. Pick a built-in icon, or upload a small image (png / svg / webp / gif / jpg, up to 512 KB): it is shown 14 px tall, so a simple mark works best.')) . '<div class="form-grid">' . form_row(t('Sort'), input('sort', (string)$edit['sort'], ['type' => 'number'])) . '</div>'
            . '<div class="form-row"><label>' . t('Who can view') . '</label>' . $vc . '<div class="form-help">' . t('Nothing checked = everyone including guests.') . '</div></div>'
            . '<div class="form-row"><label>' . t('Who can create topics') . '</label>' . $pc . '<div class="form-help">' . t('Nothing checked = any member with the "post" permission.') . '</div></div>'
            . '<div class="form-row">' . checkbox('is_hidden', (int)$edit['is_hidden'] === 1, t('Hidden (admins only)')) . '</div>'
            . admin_form_actions(t('Save'), $list_url) . '</form>';
        $drawer = ['title' => (int)$edit['id'] ? (string)$edit['name'] : t('New category'), 'sub' => (int)$edit['id'] ? t('Edit category') : '', 'body' => $body, 'back' => $list_url];
    } elseif (($del = category_by_id(get_int('delete', 0))) !== null) {
        $targets = ['0' => t('— choose —')];
        foreach (categories() as $c) if ((int)$c['id'] !== (int)$del['id']) $targets[(string)$c['id']] = $c['name'];
        $body = '<form method="post" action="' . h($list_url) . '">' . csrf_field() . '<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="' . (int)$del['id'] . '">'
            . '<p>' . t('Delete the category "%s"? Sub-categories become top level.', $del['name']) . '</p>'
            . ((int)$del['topic_count'] > 0 ? form_row(t('Move its %d topics to', (int)$del['topic_count']), select('move_to', $targets, '0')) : '<p class="muted">' . t('It has no topics.') . '</p>')
            . '<div class="form-actions sticky"><button type="submit" class="btn btn-danger">' . icon('trash') . t('Delete category') . '</button><a class="btn btn-ghost" href="' . h($list_url) . '" data-drawer-close>' . t('Cancel') . '</a></div></form>';
        $drawer = ['title' => t('Delete category'), 'sub' => (string)$del['name'], 'body' => $body, 'back' => $list_url];
    }
    admin_page(t('Categories'), $html, 'categories', ['action' => admin_drawer_link(admin_url('categories', ['edit' => 0]), t('New category'), 'btn btn-primary', 'plus'), 'drawer' => $drawer]);
}

/** The icon value to store: a built-in icon name, or the category's own uploaded image when it is kept; anything else is none. */
function admin_category_icon_value(int $id, string $posted): string
{
    if (isset(icon_paths()[$posted])) return $posted;
    $current = $id > 0 ? (string)(category_by_id($id)['icon'] ?? '') : '';
    return $posted !== '' && $posted === $current && str_contains($current, '/') ? $current : '';
}

/** Remove a category's uploaded icon file(s) (uploads/site/cat_<id>.*). */
function admin_category_icon_unlink(int $id): void
{
    foreach (glob(UPLOAD_DIR . '/site/cat_' . $id . '.*') ?: [] as $old) @unlink($old);
}

/** Built-in icons as real tiles plus the uploaded image (when any) and a file input; a click sets the hidden "icon" field (app.js). */
function admin_icon_picker(string $value): string
{
    $tile = static fn(string $v, string $inner, string $title, string $class = ''): string => '<button type="button" class="icon-pick' . $class . ($v === $value ? ' active' : '') . '" data-icon-pick="' . h($v) . '" title="' . h($title) . '">' . $inner . '</button>';
    $html = '<div class="icon-picker" data-icon-picker><input type="hidden" name="icon" value="' . h($value) . '">' . $tile('', t('None'), t('No icon'), ' icon-pick-none');
    if (str_contains($value, '/')) $html .= $tile($value, '<img src="' . h(upload_url($value)) . '" alt="">', t('Uploaded image'));
    foreach (array_keys(icon_paths()) as $n) $html .= $tile($n, icon($n), $n);
    return $html . '</div><input type="file" name="icon_file" accept=".png,.jpg,.jpeg,.gif,.webp,.svg">';
}

function admin_page_tags(): never
{
    $q = get_str('q', 30);
    $list_url = admin_url('tags', $q !== '' ? ['q' => $q] : []);
    if (is_post()) {
        check_csrf();
        $id = post_int('id');
        $tag = one('SELECT * FROM fb_tags WHERE id=?', [$id]);
        if ($tag === null) fail(t('Tag not found.'));
        if (post_str('action', 20) === 'delete') {
            db_delete('fb_topic_tags', 'tag_id=?', [$id]);
            db_delete('fb_tags', 'id=?', [$id]);
            admin_log('tag.delete', '#' . $id);
            flash(t('Tag deleted.'));
        } else {
            $name = tag_normalize(post_str('name', 30));
            if ($name === '') fail(t('Invalid tag name.'));
            $dupe = one('SELECT id FROM fb_tags WHERE slug=? AND id<>?', [$name, $id]);
            if ($dupe !== null) {
                foreach (col('SELECT topic_id FROM fb_topic_tags WHERE tag_id=?', [$id]) as $tid) db_insert_ignore('fb_topic_tags', ['topic_id' => (int)$tid, 'tag_id' => (int)$dupe['id']]);
                db_delete('fb_topic_tags', 'tag_id=?', [$id]);
                db_delete('fb_tags', 'id=?', [$id]);
                db_update('fb_tags', ['topic_count' => (int)val('SELECT COUNT(*) FROM fb_topic_tags WHERE tag_id=?', [(int)$dupe['id']])], 'id=?', [(int)$dupe['id']]);
                admin_log('tag.merge', '#' . $id, 'into ' . $name);
                flash(t('Tag merged into %s.', $name));
            } else {
                db_update('fb_tags', ['name' => $name, 'slug' => $name], 'id=?', [$id]);
                admin_log('tag.rename', '#' . $id, $name);
                flash(t('Tag renamed.'));
            }
        }
        request_cache('top_tags', null, true);
        redirect($list_url);
    }
    $where = $q !== '' ? "WHERE name LIKE ? ESCAPE '!'" : '';
    $params = $q !== '' ? [db_like(mb_strtolower($q))] : [];
    $pg = paginate_calc((int)val("SELECT COUNT(*) FROM fb_tags {$where}", $params), get_int('page', 1, 1, 10000), 50);
    $rows = [];
    foreach (all("SELECT * FROM fb_tags {$where} ORDER BY topic_count DESC, name LIMIT " . (int)$pg['per_page'] . ' OFFSET ' . (int)$pg['offset'], $params) as $tg) {
        $rows[] = [tag_badge($tg), (int)$tg['topic_count'], '<div class="row-actions">' . admin_drawer_link(admin_url('tags', ['q' => $q, 'edit' => $tg['id']]), t('Rename')) . admin_row_menu([
            '<a href="' . h(tag_url($tg)) . '">' . icon('external') . t('View') . '</a>',
            action_form($list_url, '<button type="submit" class="danger">' . icon('trash') . t('Delete') . '</button>', ['action' => 'delete', 'id' => $tg['id']], '', t('Delete this tag from every topic?')),
        ]) . '</div>'];
    }
    $html = '<form method="get" action="' . h(admin_url('tags')) . '" class="admin-toolbar">' . (rewrite_enabled() ? '' : '<input type="hidden" name="r" value="/admin/tags">') . '<input type="search" name="q" value="' . h($q) . '" placeholder="' . t('Search tags') . '"><button class="btn" type="submit">' . icon('search') . t('Search') . '</button><span class="muted small">' . t('%d tags', $pg['total']) . '</span></form>';
    $html .= admin_table([t('Tag'), t('Topics'), ''], $rows, t('No tags yet.')) . pagination($pg, static fn(int $n): string => admin_url('tags', ['q' => $q, 'page' => $n]));
    $drawer = null;
    if (($edit = one('SELECT * FROM fb_tags WHERE id=?', [get_int('edit', 0)])) !== null) {
        $body = '<form method="post" action="' . h($list_url) . '">' . csrf_field() . '<input type="hidden" name="id" value="' . (int)$edit['id'] . '">'
            . form_row(t('Name'), input('name', (string)$edit['name'], ['required' => true]), t('Renaming to an existing tag merges the two.'))
            . admin_form_actions(t('Save'), $list_url) . '</form>';
        $drawer = ['title' => '#' . $edit['name'], 'sub' => t('%d topics', (int)$edit['topic_count']), 'body' => $body, 'back' => $list_url];
    }
    admin_page(t('Tags'), $html, 'tags', ['drawer' => $drawer]);
}
