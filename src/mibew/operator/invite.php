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

require_once(dirname(dirname(__FILE__)) . '/libs/init.php');
require_once(MIBEW_FS_ROOT . '/libs/invitation.php');
require_once(MIBEW_FS_ROOT . '/libs/chat.php');
require_once(MIBEW_FS_ROOT . '/libs/operator.php');
require_once(MIBEW_FS_ROOT . '/libs/track.php');

$operator = check_login();

$visitor_id = verify_param("visitor", "/^\d{1,8}$/");

$thread = invitation_invite($visitor_id, $operator);
if (!$thread) {
    die("Invitation failed!");
}

// Open chat window for operator
$redirect_to = MIBEW_WEB_ROOT
    . '/operator/agent.php?thread=' . intval($thread->id)
    . '&token=' . urlencode($thread->lastToken);
header('Location: ' . $redirect_to);
