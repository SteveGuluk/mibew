<?php
/*
 * Copyright 2005-2014 the original author or authors.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

// Import namespaces and classes of the core
use Mibew\Database;
use Mibew\Settings;
use Mibew\Style\PageStyle;

// Initialize libraries
require_once(dirname(dirname(__FILE__)) . '/libs/init.php');
require_once(MIBEW_FS_ROOT . '/libs/chat.php');
require_once(MIBEW_FS_ROOT . '/libs/operator.php');
require_once(MIBEW_FS_ROOT . '/libs/statistics.php');
require_once(MIBEW_FS_ROOT . '/libs/cron.php');
require_once(MIBEW_FS_ROOT . '/libs/track.php');

$operator = check_login();
force_password($operator);

setlocale(LC_TIME, getstring("time.locale"));

$page = array();
$page['operator'] = to_page(get_operator_name($operator));
$page['availableDays'] = range(1, 31);
$page['availableMonth'] = get_month_selection(time() - 400 * 24 * 60 * 60, time() + 50 * 24 * 60 * 60);
$page['showresults'] = false;
$statistics_type = verify_param("type", "/^(bydate|byagent|bypage)$/", "bydate");
$page['type'] = $statistics_type;
$page['showbydate'] = ($statistics_type == 'bydate');
$page['showbyagent'] = ($statistics_type == 'byagent');
$page['showbypage'] = ($statistics_type == 'bypage');

$page['pageDescription'] = getlocal2(
    "statistics.description.full",
    array(
        date_to_text(Settings::get('_last_cron_run')),
        cron_get_uri(Settings::get('cron_key')),
    )
);

$page['show_invitations_info'] = (bool) Settings::get('enabletracking');

$page['errors'] = array();

if (isset($_GET['startday'])) {
    $start_day = verify_param("startday", "/^\d+$/");
    $start_month = verify_param("startmonth", "/^\d{2}.\d{2}$/");
    $end_day = verify_param("endday", "/^\d+$/");
    $end_month = verify_param("endmonth", "/^\d{2}.\d{2}$/");
    $start = get_form_date($start_day, $start_month);
    $end = get_form_date($end_day, $end_month) + 24 * 60 * 60;
} else {
    $curr = getdate(time());
    if ($curr['mday'] < 7) {
        // previous month
        if ($curr['mon'] == 1) {
            $month = 12;
            $year = $curr['year'] - 1;
        } else {
            $month = $curr['mon'] - 1;
            $year = $curr['year'];
        }
        $start = mktime(0, 0, 0, $month, 1, $year);
        $end = mktime(0, 0, 0, $month, date("t", $start), $year) + 24 * 60 * 60;
    } else {
        $start = mktime(0, 0, 0, $curr['mon'], 1, $curr['year']);
        $end = time() + 24 * 60 * 60;
    }
}
$page = array_merge(
    $page,
    set_form_date($start, "start"),
    set_form_date($end - 24 * 60 * 60, "end")
);

if ($start > $end) {
    $page['errors'][] = getlocal("statistics.wrong.dates");
}

$active_tab = 0;
$db = Database::getInstance();
if ($statistics_type == 'bydate') {
    $page['reportByDate'] = $db->query(
        ("SELECT DATE(FROM_UNIXTIME(date)) AS date, "
            . "threads, "
            . "missedthreads, "
            . "sentinvitations, "
            . "acceptedinvitations, "
            . "rejectedinvitations, "
            . "ignoredinvitations, "
            . "operatormessages AS agents, "
            . "usermessages AS users, "
            . "averagewaitingtime AS avgwaitingtime, "
            . "averagechattime AS avgchattime "
        . "FROM {chatthreadstatistics} s "
        . "WHERE s.date >= :start "
            . "AND s.date < :end "
        . "GROUP BY DATE(FROM_UNIXTIME(date)) "
        . "ORDER BY s.date DESC"),
        array(
            ':start' => $start,
            ':end' => $end,
        ),
        array('return_rows' => Database::RETURN_ALL_ROWS)
    );

    $page['reportByDateTotal'] = $db->query(
        ("SELECT DATE(FROM_UNIXTIME(date)) AS date, "
            . "SUM(threads) AS threads, "
            . "SUM(missedthreads) AS missedthreads, "
            . "SUM(sentinvitations) AS sentinvitations, "
            . "SUM(acceptedinvitations) AS acceptedinvitations, "
            . "SUM(rejectedinvitations) AS rejectedinvitations, "
            . "SUM(ignoredinvitations) AS ignoredinvitations, "
            . "SUM(operatormessages) AS agents, "
            . "SUM(usermessages) AS users, "
            . "ROUND(SUM(averagewaitingtime * s.threads) / SUM(s.threads),1) AS avgwaitingtime, "
            . "ROUND(SUM(averagechattime * s.threads) / SUM(s.threads),1) AS avgchattime "
        . "FROM {chatthreadstatistics} s "
        . "WHERE s.date >= :start "
            . "AND s.date < :end"),
        array(
            ':start' => $start,
            ':end' => $end,
        ),
        array('return_rows' => Database::RETURN_ONE_ROW)
    );

    $active_tab = 0;
} elseif ($statistics_type == 'byagent') {
    $page['reportByAgent'] = $db->query(
        ("SELECT o.vclocalename AS name, "
            . "SUM(s.threads) AS threads, "
            . "SUM(s.messages) AS msgs, "
            . "ROUND( "
                    . "SUM(s.averagelength * s.messages) / SUM(s.messages), "
                . "1) AS avglen, "
            . "SUM(sentinvitations) AS sentinvitations, "
            . "SUM(acceptedinvitations) AS acceptedinvitations, "
            . "SUM(rejectedinvitations) AS rejectedinvitations, "
            . "SUM(ignoredinvitations) AS ignoredinvitations "
        . "FROM {chatoperatorstatistics} s, {chatoperator} o "
        . "WHERE s.operatorid = o.operatorid "
            . "AND s.date >= :start "
            . "AND s.date < :end "
        . "GROUP BY s.operatorid"),
        array(
            ':start' => $start,
            ':end' => $end,
        ),
        array('return_rows' => Database::RETURN_ALL_ROWS)
    );

    // We need to pass operator name through "to_page" function because we
    // cannot do it in a template.
    // TODO: Remove this block when "to_page" function will be removed.
    foreach ($page['reportByAgent'] as &$row) {
        $row['name'] = to_page($row['name']);
    }
    unset($row);

    $active_tab = 1;
} elseif ($statistics_type == 'bypage') {
    $page['reportByPage'] = $db->query(
        ("SELECT SUM(visits) as visittimes, "
            . "address, "
            . "SUM(chats) as chattimes, "
            . "SUM(sentinvitations) AS sentinvitations, "
            . "SUM(acceptedinvitations) AS acceptedinvitations, "
            . "SUM(rejectedinvitations) AS rejectedinvitations, "
            . "SUM(ignoredinvitations) AS ignoredinvitations "
        . "FROM {visitedpagestatistics} "
        . "WHERE date >= :start "
            . "AND date < :end "
        . "GROUP BY address"),
        array(':start' => $start, ':end' => $end),
        array('return_rows' => Database::RETURN_ALL_ROWS)
    );
    $active_tab = 2;
}
$page['showresults'] = count($page['errors']) == 0;

$page['title'] = getlocal("statistics.title");
$page['menuid'] = "statistics";

$page = array_merge($page, prepare_menu($operator));

$page['tabs'] = setup_statistics_tabs($active_tab);

$page_style = new PageStyle(PageStyle::currentStyle());
$page_style->render('statistics', $page);
