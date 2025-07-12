<?php
// Disallow direct access to this file for security reasons
if(!defined("IN_MYBB"))
{
    die("Direct initialization of this file is not allowed.");
}

// === BUCKETLIST PLUGIN v1.0 (Final - MyAlerts & Mention Fix) ===

// ### MyAlerts Integration (KORRIGIERT nach dem Vorbild von inactivity_blacklist) ###
$plugins->add_hook('global_start', 'bucketlist_register_formatter');
$plugins->add_hook('xmlhttp', 'bucketlist_register_formatter', -2);
$plugins->add_hook('myalerts_register_client_alert_formatters', 'bucketlist_register_formatter');

// ### Standard Hooks ###
$plugins->add_hook("usercp_menu", "bucketlist_nav", 91);
$plugins->add_hook("usercp_start", "bucketlist_ucp_page");
$plugins->add_hook("deletepost_do_deletepost_start", "bucketlist_delete_plan");


function bucketlist_info()
{
    return array(
        "name"          => "Charakter Bucketlist",
        "description"   => "Ermöglicht Benutzern, eine Bucketlist für ihre Charaktere im UCP zu verwalten, die in einem Forum gepostet wird. Integriert MentionMe und MyAlerts.",
        "website"       => "https://shadow.or.at",
        "author"        => "Dani & Gemini",
        "authorsite"    => "https://github.com/ShadowOfDestiny",
        "version"       => "1.1",
        "compatibility" => "18*"
    );
}

function bucketlist_install()
{
    global $db, $cache;
    // Datenbanktabellen (unverändert)
    if (!$db->table_exists("bucketlists_main")) {
        $db->write_query("CREATE TABLE ".TABLE_PREFIX."bucketlists_main (
            `uid` int(10) unsigned NOT NULL, 
            `tid` int(10) unsigned NOT NULL, 
            `title` varchar(255) NOT NULL, 
            PRIMARY KEY (`uid`)
        ) ENGINE=InnoDB;");
    }
    if (!$db->table_exists("bucketlists_plans")) {
        $db->write_query("CREATE TABLE ".TABLE_PREFIX."bucketlists_plans (
            `plan_id` int(10) unsigned NOT NULL AUTO_INCREMENT, 
            `uid` int(10) unsigned NOT NULL, 
            `pid` int(10) unsigned NOT NULL, 
            `plan_text` TEXT NOT NULL, 
            `mentions` TEXT NOT NULL,
            `status` ENUM('open', 'done') NOT NULL DEFAULT 'open', 
            `dateline` bigint(30) NOT NULL, 
            PRIMARY KEY (`plan_id`), 
            KEY `uid` (`uid`)
        ) ENGINE=InnoDB;");
    }
    
    // Einstellungen (unverändert)
    $setting_group = [
        'name' => 'bucketlist', 
        'title' => 'Bucketlist Einstellungen', 
        'description' => 'Einstellungen für das Bucketlist Plugin.', 
        'disporder' => 6, 
        'isdefault' => 0
    ];
    $gid = $db->insert_query("settinggroups", $setting_group);
    
    $settings = [
        'bucketlist_fid' => [
            'title' => 'Forum für Bucketlists', 
            'description' => 'ID des Forums, in dem die Bucketlist-Themen erstellt werden.', 
            'optionscode' => 'forumselect', 
            'value' => '1', 
            'disporder' => 1, 
            'gid' => (int)$gid
        ],
        'bucketlist_gids' => [
            'title' => 'Erlaubte Benutzergruppen', 
            'description' => 'Welche Gruppen dürfen eine Bucketlist erstellen?', 
            'optionscode' => 'groupselect', 
            'value' => '2', 
            'disporder' => 2, 
            'gid' => (int)$gid
        ]
    ];
    foreach($settings as $name => $setting) {
        $db->insert_query("settings", array_merge($setting, ['name' => $name]));
    }
    
    // Template-Gruppe erstellen (unverändert)
    if ($db->num_rows($db->simple_select("templategroups", "gid", "prefix='bucketlist'")) == 0) {
        $db->insert_query("templategroups", ["prefix" => "bucketlist", "title" => "Bucketlist"]);
    }

    // MyAlerts Alert-Typ registrieren
    if (function_exists('myalerts_info')) {
        $alertTypeManager = \MybbStuff_MyAlerts_AlertTypeManager::getInstance();
        if (!$alertTypeManager) {
            myalerts_create_instances();
            $alertTypeManager = \MybbStuff_MyAlerts_AlertTypeManager::getInstance();
        }
        if ($alertTypeManager) {
            if ($db->num_rows($db->simple_select('alert_types', 'id', "code = 'bucketlist_mention'")) == 0) {
                $alertType = new \MybbStuff_MyAlerts_Entity_AlertType();
                $alertType->setCode('bucketlist_mention')->setEnabled(true)->setCanBeUserDisabled(true);
                $alertTypeManager->add($alertType);
            }
        }
    }
    rebuild_settings();
}

function bucketlist_is_installed()
{
    global $db;
    return $db->table_exists("bucketlists_main");
}

function bucketlist_uninstall()
{
    global $db, $cache;
    if ($db->table_exists("bucketlists_main")) { $db->drop_table("bucketlists_main"); }
    if ($db->table_exists("bucketlists_plans")) { $db->drop_table("bucketlists_plans"); }
    $db->delete_query('settings', "name LIKE 'bucketlist_%'");
    $db->delete_query('settinggroups', "name = 'bucketlist'");
    
    $db->delete_query("templates", "title LIKE 'bucketlist_%'");
    $db->delete_query("templategroups", "prefix='bucketlist'");

    // ### MyAlerts Alert-Typ entfernen ###
    if (function_exists('myalerts_info')) {
        $alertTypeManager = \MybbStuff_MyAlerts_AlertTypeManager::getInstance();
        if (!$alertTypeManager) { myalerts_create_instances(); $alertTypeManager = \MybbStuff_MyAlerts_AlertTypeManager::getInstance(); }
        if ($alertTypeManager) {
            $alertTypeManager->deleteByCode('bucketlist_mention');
        }
    }
    rebuild_settings();
}

function bucketlist_activate()
{
    bucketlist_manage_templates();
}

function bucketlist_deactivate()
{
    global $db;
    $db->delete_query("templates", "title LIKE 'bucketlist_%' AND sid='-2'");
}

function bucketlist_manage_templates()
{
    global $db;
    $info = bucketlist_info();
    $templates = [];

    // Saubere, formatierte Templates
    $templates['bucketlist_nav_usercp'] = '
<tr>
    <td class="trow1"><a href="usercp.php?action=bucketlist" class="usercp_nav_item">{$lang->bucketlist_ucp_link}</a></td>
</tr>';

   $templates['bucketlist_ucp_page'] = '
<html>
    <head>
        <title>{$lang->bucketlist_title}</title>
        {$headerinclude}
    </head>
    <body>
        {$header}
        <table width="100%" border="0" align="center">
            <tr>
                {$usercpnav}
                <td valign="top">
                    {$errors}
                    {$page_content}
                </td>
            </tr>
        </table>
        {$select2_javascript}
        {$footer}
    </body>
</html>';

    $templates['bucketlist_ucp_plan_list'] = '
    <form method="post" action="usercp.php">
        <input type="hidden" name="my_post_key" value="{$mybb->post_code}" />
        <input type="hidden" name="action" value="do_update_title" />
        <table class="tborder" border="0" cellspacing="1" cellpadding="4" width="100%">
            <tr>
                <td class="thead"><strong>{$lang->bucketlist_edit_title}</strong></td>
            </tr>
            <tr>
                <td class="trow1">
                    <input type="text" class="textbox" name="title" value="{$bucketlist[\'title\']}" style="width: 80%;" />
                    <input type="submit" class="button" value="{$lang->bucketlist_submit_update}" />
                </td>
            </tr>
        </table>
    </form>
    <br />
<table class="tborder" border="0" cellspacing="1" cellpadding="4" width="100%">
    <thead>
        <tr>
            <td class="thead" colspan="3">
                <strong>{$lang->bucketlist_current_title}: {$bucketlist[\'title\']}</strong>
                <div class="float_right">
                    <a href="{$bucketlist_thread_link}" target="_blank" class="button">
                        <span>{$lang->bucketlist_view_thread}</span>
                    </a>
                </div>
            </td>
        </tr>
        <tr>
            <td class="tcat" width="70%">{$lang->bucketlist_form_plan}</td>
            <td class="tcat" width="10%">{$lang->bucketlist_status}</td>
            <td class="tcat" width="20%">{$lang->bucketlist_actions}</td>
        </tr>
    </thead>
    <tbody>
        {$plan_rows}
    </tbody>
</table>
<br />';

    // Zeile für einen offenen Plan
	$templates['bucketlist_ucp_plan_row'] = '
<tr>
    <td class="{$trow}">{$plan_text}</td>
    <td class="{$trow}">{$status_text}</td>
    <td class="{$trow}">{$action_buttons}</td>
</tr>';

	// Zeile für einen erledigten Plan (durchgestrichen)
	$templates['bucketlist_ucp_plan_row_done'] = '
<tr>
    <td class="{$trow}"><del>{$plan_text}</del></td>
    <td class="{$trow}">{$status_text}</td>
    <td class="{$trow}">{$action_buttons}</td>
</tr>';

    $templates['bucketlist_ucp_createlist'] = '
<form method="post" action="usercp.php">
    <input type="hidden" name="my_post_key" value="{$mybb->post_code}" />
    <input type="hidden" name="action" value="do_create_list" />
    <table border="0" cellspacing="1" cellpadding="4" class="tborder">
        <thead>
            <tr>
                <th class="thead"><strong>{$lang->bucketlist_create_new}</strong></th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td class="trow1">
                    <strong>{$lang->bucketlist_form_title}:</strong><br />
                    <input type="text" class="textbox" name="title" size="40" required />
                </td>
            </tr>
            <tr>
                <td class="trow2">
                    <strong>{$lang->bucketlist_form_first_plan}:</strong><br />
                    <textarea name="plan" rows="10" cols="70"></textarea>
                </td>
            </tr>
            <tr>
                <td class="trow1">
                    {$mentions_html}
                </td>
            </tr>
        </tbody>
    </table>
    <br />
    <div align="center">
        <input type="submit" class="button" value="{$lang->bucketlist_submit_create}" />
    </div>
</form>';

    $templates['bucketlist_ucp_addplan'] = '
<form method="post" action="usercp.php">
    <input type="hidden" name="my_post_key" value="{$mybb->post_code}" />
    <input type="hidden" name="action" value="do_add_plan" />
    <table border="0" cellspacing="1" cellpadding="4" class="tborder">
        <thead>
            <tr>
                <th class="thead"><strong>{$lang->bucketlist_add_plan}</strong></th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td class="trow1">
                    <strong>{$lang->bucketlist_form_plan}:</strong><br />
                    <textarea name="plan" rows="10" cols="70"></textarea>
                </td>
            </tr>
            <tr>
                <td class="trow2">
                    {$mentions_html}
                </td>
            </tr>
        </tbody>
    </table>
    <br />
    <div align="center">
        <input type="submit" class="button" value="{$lang->bucketlist_submit_add}" />
    </div>
</form>';

    $templates['bucketlist_ucp_editplan'] = '
<form method="post" action="usercp.php">
    <input type="hidden" name="my_post_key" value="{$mybb->post_code}" />
    <input type="hidden" name="action" value="do_update_plan" />
    <input type="hidden" name="plan_id" value="{$plan[\'plan_id\']}" />
    <table border="0" cellspacing="1" cellpadding="4" class="tborder">
        <thead>
            <tr>
                <th class="thead" colspan="2"><strong>{$lang->bucketlist_edit_plan}</strong></th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td class="trow1">
                    <strong>{$lang->bucketlist_form_plan}:</strong><br />
                    <textarea name="plan" rows="10" cols="70">{$plan[\'plan_text\']}</textarea>
                </td>
            </tr>
            <tr>
                <td class="trow2">
                    {$mentions_html}
                </td>
            </tr>
        </tbody>
    </table>
    <br />
    <div align="center">
        <input type="submit" class="button" value="{$lang->bucketlist_submit_update}" />
    </div>
</form>';

    foreach ($templates as $title => $template_content) {
        $template_array = ['title' => $db->escape_string($title), 'template' => $db->escape_string($template_content), 'sid' => -2, 'version' => $db->escape_string($info['version']), 'dateline' => time()];
        $db->delete_query("templates", "title = '{$template_array['title']}' AND sid = '-2'");
        $db->insert_query("templates", $template_array);
    }
}

function bucketlist_nav() {
    global $templates, $lang, $usercpmenu;
    $lang->load("bucketlist");
    eval("\$usercpmenu .= \"".$templates->get("bucketlist_nav_usercp")."\";");
}

function bucketlist_post_to_thread($tid, $subject, $message, $action = "post") {
    require_once MYBB_ROOT."inc/datahandlers/post.php";
    $posthandler = new PostDataHandler("insert");
    $post_data = ["subject" => $subject, "uid" => $GLOBALS['mybb']->user['uid'], "username" => $GLOBALS['mybb']->user['username'], "message" => $message, "ipaddress" => get_ip(), "dateline" => TIME_NOW, "options" => ["signature" => 1, "subscriptionmethod" => 1, "disablesmilies" => 0]];
    if ($action == "thread") { $post_data["fid"] = (int)$GLOBALS['mybb']->settings['bucketlist_fid']; $posthandler->action = "thread"; }
    else { $post_data["tid"] = $tid; $thread = get_thread($tid); $post_data["fid"] = $thread['fid']; $posthandler->action = "post"; }
    $posthandler->set_data($post_data);
    if($posthandler->validate_thread() || $posthandler->validate_post()) { return ($action == "thread") ? $posthandler->insert_thread() : $posthandler->insert_post(); }
    else { return $posthandler->get_friendly_errors(); }
}

function bucketlist_ucp_page()
{
    global $mybb, $db, $lang, $templates, $header, $headerinclude, $footer, $usercpnav;
    
    // Aktion für Titel-Update hinzugefügt
    $allowed_actions = ['bucketlist', 'do_create_list', 'do_add_plan', 'do_mark_done', 'edit_plan', 'do_update_plan', 'do_update_title'];
    if (!in_array($mybb->input['action'], $allowed_actions)) return;

    $lang->load("bucketlist");
    $lang->load("search");
    $allowed_groups = explode(',', $mybb->settings['bucketlist_gids']);
    if (!is_member($allowed_groups)) error_no_permission();
    
    add_breadcrumb($lang->bucketlist_ucp_link, "usercp.php?action=bucketlist");
    $errors = $page_content = '';
    
    function format_bucketlist_message($plan_text, $mentions_clean) {
        global $lang;
        $message = $plan_text;
        if(!empty($mentions_clean)) {
            // KORREKTUR 2: Leerzeichen nach Komma einfügen
            $mentions_display = str_replace(',', ', ', $mentions_clean);
            $message .= "\n\n[b]{$lang->bucketlist_form_mentions}:[/b] " . htmlspecialchars_uni($mentions_display);
        }
        return $message;
    }

    if($mybb->request_method == 'post') {
        verify_post_check($mybb->get_input('my_post_key'));
        $plan_text = $mybb->get_input('plan');
        $mentions_clean = $mybb->get_input('mentions');

        if($mybb->input['action'] == 'do_create_list') {
            $title = $mybb->get_input('title');
            $message = format_bucketlist_message($plan_text, $mentions_clean);
            $thread_info = bucketlist_post_to_thread(0, $title, $message, "thread");
            if(is_array($thread_info)) {
                $db->insert_query("bucketlists_main", ['uid' => $mybb->user['uid'], 'tid' => $thread_info['tid'], 'title' => $db->escape_string($title)]);
                $db->insert_query("bucketlists_plans", ['uid' => $mybb->user['uid'], 'pid' => $thread_info['pid'], 'plan_text' => $db->escape_string($plan_text), 'mentions' => $db->escape_string($mentions_clean), 'status' => 'open', 'dateline' => TIME_NOW]);
                bucketlist_send_alerts($mentions_clean, $mybb->user['uid'], $thread_info['pid']);
                redirect("usercp.php?action=bucketlist", $lang->bucketlist_success_created);
            } else { $errors = inline_error($thread_info); }
        }
        
        if($mybb->input['action'] == 'do_add_plan') {
            $bucketlist = $db->fetch_array($db->simple_select("bucketlists_main", "*", "uid = '{$mybb->user['uid']}'"));
            if($bucketlist) {
                $message = format_bucketlist_message($plan_text, $mentions_clean);
                $post_info = bucketlist_post_to_thread($bucketlist['tid'], "Re: ".$bucketlist['title'], $message);
                if(is_array($post_info)) {
                    $db->insert_query("bucketlists_plans", ['uid' => $mybb->user['uid'], 'pid' => $post_info['pid'], 'plan_text' => $db->escape_string($plan_text), 'mentions' => $db->escape_string($mentions_clean), 'status' => 'open', 'dateline' => TIME_NOW]);
                    bucketlist_send_alerts($mentions_clean, $mybb->user['uid'], $post_info['pid']);
                    redirect("usercp.php?action=bucketlist", $lang->bucketlist_success_plan_added);
                } else { $errors = inline_error($post_info); }
            }
        }
        
        if($mybb->input['action'] == 'do_update_plan') {
            $plan_id = $mybb->get_input('plan_id', 1);
            $plan_db = $db->fetch_array($db->simple_select("bucketlists_plans", "*", "plan_id='{$plan_id}' AND uid='{$mybb->user['uid']}'"));
            if($plan_db) {
                $message = format_bucketlist_message($plan_text, $mentions_clean);
                $db->update_query("bucketlists_plans", ['plan_text' => $db->escape_string($plan_text), 'mentions' => $db->escape_string($mentions_clean)], "plan_id='{$plan_id}'");
                require_once MYBB_ROOT."inc/datahandlers/post.php";
                $posthandler = new PostDataHandler("update");
                $posthandler->set_data(['pid' => $plan_db['pid'], 'message' => $message]);
                if($posthandler->validate_post()) {
                    $posthandler->update_post();
                    bucketlist_send_alerts($mentions_clean, $mybb->user['uid'], $plan_db['pid']);
                    redirect("usercp.php?action=bucketlist", $lang->bucketlist_success_plan_updated);
                } else { $errors = inline_error($posthandler->get_friendly_errors()); }
            }
        }
		
		// NEU: Aktion zum Speichern des Titels
        if($mybb->input['action'] == 'do_update_title') {
            $new_title = $mybb->get_input('title');
            $bucketlist = $db->fetch_array($db->simple_select("bucketlists_main", "tid", "uid = '{$mybb->user['uid']}'"));
            if($bucketlist && !empty($new_title)) {
                // Titel in unserer Plugin-Tabelle aktualisieren
                $db->update_query("bucketlists_main", ['title' => $db->escape_string($new_title)], "uid = '{$mybb->user['uid']}'");
                
                // Thementitel im Forum aktualisieren
                require_once MYBB_ROOT."inc/datahandlers/post.php";
                $posthandler = new PostDataHandler("update");
                $posthandler->action = "thread";
                $posthandler->set_data(['tid' => $bucketlist['tid'], 'subject' => $new_title]);
                if($posthandler->validate_thread()) {
                    $posthandler->update_thread();
                    redirect("usercp.php?action=bucketlist", $lang->bucketlist_success_title_updated);
                } else {
                    $errors = inline_error($posthandler->get_friendly_errors());
                }
            }
        }
    }
    
    if($mybb->input['action'] == 'do_mark_done' && $mybb->get_input('plan_id', 1)) {
        verify_post_check($mybb->get_input('my_post_key'));
        $plan_id = $mybb->get_input('plan_id', 1);
        $db->update_query("bucketlists_plans", ['status' => 'done'], "plan_id = '{$plan_id}' AND uid = '{$mybb->user['uid']}'");
        redirect("usercp.php?action=bucketlist", $lang->bucketlist_success_plan_marked);
    }
    
    function build_mentions_input($current_value = "") {
        global $lang;
        $mentions_html = "<strong>{$lang->bucketlist_form_mentions}:</strong><br /><small>{$lang->bucketlist_form_mentions_desc}</small><br />";
        $mentions_html .= "<input type=\"text\" class=\"textbox\" name=\"mentions\" id=\"bucketlist_mentions\" value=\"".htmlspecialchars_uni($current_value)."\" />";
        return $mentions_html;
    }
    
    $mentions_html = build_mentions_input();
    
    if($mybb->input['action'] == 'edit_plan' && $mybb->get_input('plan_id', 1)) {
        $plan_id = $mybb->get_input('plan_id', 1);
        $plan = $db->fetch_array($db->simple_select("bucketlists_plans", "*", "plan_id='{$plan_id}' AND uid='{$mybb->user['uid']}'"));
        if($plan) { 
            add_breadcrumb($lang->bucketlist_edit_plan);
            $mentions_html = build_mentions_input($plan['mentions']);
            eval("\$page_content = \"".$templates->get("bucketlist_ucp_editplan")."\";");
        } 
        else { error($lang->bucketlist_error_not_found); }
    }
    
    if($mybb->input['action'] == 'bucketlist') {
        $bucketlist = $db->fetch_array($db->simple_select("bucketlists_main", "*", "uid = '{$mybb->user['uid']}'"));
        if ($bucketlist) {
            $plan_rows = '';
            $bucketlist_thread_link = $mybb->settings['bburl'] . "/showthread.php?tid=" . (int)$bucketlist['tid'];
			
			// KORREKTUR 3: MyCode-Parser für die UCP-Ansicht initialisieren
            require_once MYBB_ROOT.'inc/class_parser.php';
            $parser = new postParser;
            $parser_options = ['allow_mycode' => 1, 'allow_smilies' => 1, 'allow_html' => 1, 'allow_imgcode' => 0];
			
            $query = $db->simple_select("bucketlists_plans", "*", "uid='{$mybb->user['uid']}'", ['order_by' => 'dateline', 'order_dir' => 'ASC']);
            while($plan = $db->fetch_array($query)) {
                $trow = alt_trow();
                // Den Plan-Text durch den Parser jagen, um Listen etc. korrekt darzustellen
                $plan_text = $parser->parse_message($plan['plan_text'], $parser_options);
                if(!empty($plan['mentions'])) { $plan_text .= "<br /><small><i>{$lang->bucketlist_form_mentions}: " . htmlspecialchars_uni($plan['mentions']) . "</i></small>"; }
                $action_buttons = '<a href="usercp.php?action=edit_plan&plan_id='.$plan['plan_id'].'">'.$lang->bucketlist_edit.'</a>';
                if($plan['status'] == 'open') {
                    $status_text = $lang->bucketlist_status_open;
                    $action_buttons .= ' | <a href="usercp.php?action=do_mark_done&plan_id='.$plan['plan_id'].'&my_post_key='.$mybb->post_code.'">'.$lang->bucketlist_mark_done.'</a>';
                    eval("\$plan_rows .= \"".$templates->get("bucketlist_ucp_plan_row")."\";");
                } else {
                    $status_text = $lang->bucketlist_status_done;
                    eval("\$plan_rows .= \"".$templates->get("bucketlist_ucp_plan_row_done")."\";");
                }
            }
            eval("\$page_content = \"".$templates->get("bucketlist_ucp_plan_list")."\";");
            eval("\$page_content .= \"".$templates->get("bucketlist_ucp_addplan")."\";");
        } else {
            eval("\$page_content = \"".$templates->get("bucketlist_ucp_createlist")."\";");
        }
    }
    
    $select2_javascript = '';
    if (in_array($mybb->input['action'], ['bucketlist', 'edit_plan'])) {
        $lang_search_user = $lang->search_user;
        $select2_javascript = <<<HTML
<style type="text/css"> #s2id_bucketlist_mentions { width: 90%; max-width: 550px; } </style>
<link rel="stylesheet" href="{$mybb->asset_url}/jscripts/select2/select2.css?ver=1807">
<script type="text/javascript" src="{$mybb->asset_url}/jscripts/select2/select2.min.js?ver=1806"></script>
<script type="text/javascript">
if(typeof use_xmlhttprequest !== 'undefined' && use_xmlhttprequest == "1") {
    MyBB.select2();
    $("#bucketlist_mentions").select2({
        placeholder: "{$lang_search_user}",
        minimumInputLength: 2,
        maximumSelectionSize: "",
        multiple: true,
        ajax: { 
            url: "xmlhttp.php?action=get_users",
            dataType: 'json',
            data: function (term, page) { return { query: term }; },
            results: function (data, page) { return {results: data}; }
        },
        initSelection: function(element, callback) {
            var query = $(element).val();
            if (query !== "") {
                var newqueries = [];
                var exp_queries = query.split(",");
                $.each(exp_queries, function(index, value){
                    var trimmed_value = $.trim(value);
                    if(trimmed_value != "") {
                        var newquery = { id: trimmed_value, text: trimmed_value };
                        newqueries.push(newquery);
                    }
                });
                callback(newqueries);
            }
        }
    });
}
</script>
HTML;
    }

    eval("\$page = \"".$templates->get("bucketlist_ucp_page")."\";");
    output_page($page);
    exit;
}

function bucketlist_delete_plan() {
    global $db, $post;
    if(isset($post['pid'])) { $db->delete_query("bucketlists_plans", "pid = '{$post['pid']}'"); }
}

function bucketlist_send_alerts($mentions_clean, $from_uid, $pid) {
    global $mybb;
    if (function_exists('myalerts_info') && !empty($mentions_clean)) {
        $alertType = MybbStuff_MyAlerts_AlertTypeManager::getInstance()->getByCode('bucketlist_mention');
        if ($alertType && $alertType->getEnabled()) {
            $mentioned_users = explode(',', $mentions_clean);
            foreach($mentioned_users as $username) {
                $username = trim($username);
                if(empty($username)) continue;
                $to_user = get_user_by_username($username);
                if($to_user) {
                    $alert = new \MybbStuff_MyAlerts_Entity_Alert((int)$to_user['uid'], $alertType, (int)$from_uid);
                    $alert->setExtraDetails(['pid' => $pid]);
                    \MybbStuff_MyAlerts_AlertManager::getInstance()->addAlert($alert);
                }
            }
        }
    }
}

// ### KORRIGIERTER MyAlerts Formatter ###
function bucketlist_register_formatter() {
    global $mybb, $lang;
    if (class_exists('MybbStuff_MyAlerts_Formatter_AbstractFormatter') && class_exists('MybbStuff_MyAlerts_AlertFormatterManager')) {
        $formatterManager = \MybbStuff_MyAlerts_AlertFormatterManager::getInstance();
        if (!$formatterManager) { myalerts_create_instances(); $formatterManager = \MybbStuff_MyAlerts_AlertFormatterManager::getInstance(); }
        if ($formatterManager) {
            $formatterManager->registerFormatter( new BucketlistMentionAlertFormatter($mybb, $lang, 'bucketlist_mention') );
        }
    }
}

if (!class_exists('BucketlistMentionAlertFormatter')) {
    class BucketlistMentionAlertFormatter extends \MybbStuff_MyAlerts_Formatter_AbstractFormatter
    {
        public function init() {
            if(!$this->lang->bucketlist) {
                $this->lang->load('bucketlist');
            }
        }

        public function formatAlert(\MybbStuff_MyAlerts_Entity_Alert $alert, array $outputAlert) {
            $this->init();
            $alertContent = $alert->getExtraDetails();
            $post_link = $this->mybb->settings['bburl'] . '/' . get_post_link($alertContent['pid']) . '#pid' . $alertContent['pid'];
            
            return $this->lang->sprintf(
                $this->lang->bucketlist_alert_mention,
                $outputAlert['from_user'],
                $post_link
            );
        }

        public function buildShowLink(\MybbStuff_MyAlerts_Entity_Alert $alert) {
            $alertContent = $alert->getExtraDetails();
            return get_post_link($alertContent['pid']) . '#pid' . $alertContent['pid'];
        }
    }
}
?>