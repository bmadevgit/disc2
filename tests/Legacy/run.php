<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

function same(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($label . "\nExpected " . var_export($expected, true) . "\nGot " . var_export($actual, true));
    }
}

function checkInventory(string $root): void
{
    $rows = [];
    $document = dirname(__DIR__, 2).'/docs/rewrite/route-contract.md';
    foreach (is_file($document) ? file($document, FILE_IGNORE_NEW_LINES) : [] as $line) {
        if (!preg_match('~^\| `/([a-z0-9_]+\.php)` \|~', $line, $match)) continue;
        $name = $match[1];
        $cells = array_map('trim', explode('|', trim($line, '|')));
        same(7, count($cells), "$name needs path, class, method, inputs, response, access and replacement");
        same(false, in_array('', $cells, true), "$name has a missing contract field");
        same(true, in_array($cells[1], ['page','write endpoint','redirect alias','JSON endpoint','CLI-only','retired-410','internal include'], true), "$name classification");
        if ($cells[1] === 'internal include') {
            same(['Internal','No public route'], [$cells[2],$cells[6]], "$name must stay internal");
        } elseif ($cells[1] === 'CLI-only') {
            same('CLI', $cells[2], "$name CLI contract");
        } else {
            same(1, preg_match('/^(?:GET|POST|Any)\b/', $cells[2]), "$name HTTP method");
            same(1, preg_match('/^[a-z][a-z0-9_]*$/D', $cells[6]), "$name Symfony route name");
        }
        same(false, isset($rows[$name]), "$name is classified twice");
        $rows[$name] = true;
    }
    $files = array_map('basename', glob($root . '/*.php'));
    $listed = array_keys($rows);
    sort($files);
    sort($listed);
    same($files, $listed, 'Root PHP inventory mismatch');
}

final class Browser
{
    private CurlHandle $curl;

    public function __construct(private string $base)
    {
        $this->curl = curl_init();
        curl_setopt_array($this->curl, [
            CURLOPT_COOKIEFILE => '', CURLOPT_HEADER => true, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 5, CURLOPT_PROXY => '',
        ]);
    }

    public function request(string $path, int $status, ?array $post = null, ?string $location = null): string
    {
        curl_setopt($this->curl, CURLOPT_URL, $this->base . $path);
        if ($post === null) {
            curl_setopt($this->curl, CURLOPT_HTTPGET, true);
        } else {
            curl_setopt($this->curl, CURLOPT_POSTFIELDS, http_build_query($post));
        }
        $output = curl_exec($this->curl);
        if ($output === false) throw new RuntimeException(curl_error($this->curl));
        $headers = substr($output, 0, curl_getinfo($this->curl, CURLINFO_HEADER_SIZE));
        $body = substr($output, strlen($headers));
        $method = $post === null ? 'GET' : 'POST';
        $label = "$method $path " . ($post['action'] ?? '');
        same($status, curl_getinfo($this->curl, CURLINFO_RESPONSE_CODE), $label);
        if ($location !== null) {
            preg_match('/^Location: (.+)$/mi', $headers, $match);
            same($location, trim($match[1] ?? ''), "$label redirect");
        }
        return $body;
    }

    public function login(string $username, string $password): void
    {
        $csrf = field($this->request('/login.php', 200), 'csrf');
        $this->request('/login.php', 302, compact('username', 'password', 'csrf'), '/index.php');
    }
}

function field(string $html, string $name): string
{
    if (!preg_match('/name="' . preg_quote($name, '/') . '" value="([^"]+)"/', $html, $match)) {
        throw new RuntimeException("Missing form field $name");
    }
    return html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
}

function containsText(string $body, string $needle, bool $expected, string $label): void
{
    same($expected, str_contains($body, $needle), $label);
}

function seedRoles(PDO $db, array $accounts): array
{
    $insert = $db->prepare("INSERT INTO users(username,name,role,password_hash) VALUES(?,?,'user',?)");
    $assign = $db->prepare('INSERT INTO uat_access_assignments(bma_personal_id,display_name,access_role,unit_level1,unit_level2) VALUES(?,?,?,?,?)');
    foreach (['executive','unit_head','unrelated','unassigned'] as $name) {
        $password = bin2hex(random_bytes(12));
        $insert->execute([$name, $name, password_hash($password, PASSWORD_DEFAULT)]);
        $assign->execute([$db->lastInsertId(), $name, 'case_owner', 'สำนักงานตัวอย่าง', 'กลุ่มงานวินัยตัวอย่าง']);
        $accounts[$name] = $password;
    }

    // Obtain a genuine authenticated form token before applying each read-only assignment.
    $db->exec("UPDATE uat_access_assignments SET access_role='case_owner'
        WHERE bma_personal_id=(SELECT id FROM users WHERE username='demo-reader')");

    $case = $db->prepare('INSERT INTO cases(เลขที่สำนวน,unit_level1) VALUES(?,?)');
    $meta = $db->prepare('INSERT INTO uat_case_meta(case_id,unit_level1,unit_level2) VALUES(?,?,?)');
    foreach ([
        ['SAME-UNIT', 'สำนักงานตัวอย่าง', 'Different group'],
        ['OTHER-UNIT', 'Different unit', 'กลุ่มงานวินัยตัวอย่าง'],
    ] as [$number, $unit, $group]) {
        $case->execute([$number, $unit]);
        $meta->execute([$db->lastInsertId(), $unit, $group]);
    }
    // An ended owner remains in history but has no access to this case.
    $db->exec("INSERT INTO uat_case_owners(case_id,bma_personal_id,owner_name,ended_at)
        SELECT 1,id,name,'2024-01-01' FROM users WHERE username='unrelated'");
    $db->exec("UPDATE uat_subjects SET reference_code='' WHERE sequence_no=2");
    return $accounts;
}

function caseAndAuditState(PDO $db): array
{
    $state = [];
    foreach (['cases','uat_case_meta','uat_case_owners','uat_subjects','uat_tracks',
        'uat_track_subjects','uat_subject_findings','uat_audit_log'] as $table) {
        $state[$table] = $db->query("SELECT * FROM $table ORDER BY rowid")->fetchAll();
    }
    return $state;
}

function checkRoles(PDO $db, string $base, array $accounts): Browser
{
    $public = new Browser($base);
    foreach (['/', '/case.php?id=1', '/save_case.php', '/admin.php', '/users.php'] as $path) {
        $public->request($path, 302, null, '/login.php');
    }
    $public->request('/i.php', 302, null, '/index.php');
    foreach (['save.php','update_case.php','update_unit_target.php'] as $retired) {
        $public->request('/'.$retired, 410);
    }
    $public->request('/migrate.php', 404);
    $public->request('/save_case.php', 302, ['action'=>'save_fact'], '/login.php');

    $readOnlyRoles = ['demo-reader'=>'group_head','executive'=>'executive','unit_head'=>'unit_head'];
    $assignRole = $db->prepare('UPDATE uat_access_assignments SET access_role=?
        WHERE bma_personal_id=(SELECT id FROM users WHERE username=?)');
    foreach ($accounts as $username => $password) {
        $browser = new Browser($base);
        $browser->login($username, $password);
        $csrf = field($browser->request('/index.php', 200), 'csrf');
        if (isset($readOnlyRoles[$username])) {
            $assignRole->execute([$readOnlyRoles[$username], $username]);
        } elseif ($username === 'unassigned') {
            $db->exec("DELETE FROM uat_access_assignments WHERE bma_personal_id=(SELECT id FROM users WHERE username='unassigned')");
        }
        $admin = $username === 'demo-admin';
        $owner = $username === 'demo-owner';
        $reader = $username === 'demo-reader';
        $canRead = $admin || $owner || $reader;
        $aggregate = in_array($username, ['executive','unit_head'], true);
        if ($aggregate) {
            $browser->request('/index.php', 302, null, '/admin.php');
        } else {
            $list = $browser->request('/index.php', 200);
            containsText($list, '<b>DEMO-001</b>', $canRead, "$username list");
            containsText($list, '<b>SAME-UNIT</b>', $admin, "$username other group");
            containsText($list, '<b>OTHER-UNIT</b>', $admin, "$username other unit");
            containsText($list, 'id="new-case-form"', $owner || $admin || $username === 'unrelated', "$username create");
            foreach (['บุคคลสมมติ ก'=>$admin, 'REF-DEMO-1'=>$canRead] as $term=>$visible) {
                $search = $browser->request('/index.php?q='.rawurlencode($term), 200);
                containsText($search, '<b>DEMO-001</b>', $visible, "$username search $term");
            }
        }
        if ($admin || $aggregate) {
            $dash = $browser->request('/admin.php', 200);
            $total = $username === 'unit_head' ? 2 : 3;
            containsText($dash, "สำนวนทั้งหมด</span><b>$total</b>", true, "$username dashboard scope");
        } else {
            $browser->request('/admin.php', 302, null, '/index.php');
        }
        $detail = $browser->request('/case.php?id=1&page=2', $canRead ? 200 : 403);
        containsText($detail, 'value="save_fact"', $admin || $owner, "$username fact edit form");
        $browser->request('/case.php?id=2', $admin ? 200 : 403);
        $browser->request('/case.php?id=3', $admin ? 200 : 403);
        $people = $browser->request('/subject.php?case_id=1&subject_id=1', $canRead ? 200 : 403);
        containsText($people, 'บุคคลสมมติ ก', $admin, "$username subject identity");
        if ($canRead && !$admin) {
            containsText($people, 'REF-DEMO-1', true, "$username reference label");
            containsText($people, 'ผู้ถูกกล่าวหารายที่ 2', true, "$username fallback label");
        }
        $browser->request('/roles.php', $admin ? 200 : 403);
        $browser->request('/users.php', $admin ? 302 : 403, null, $admin ? '/roles.php' : null);
        $browser->request('/profile.php', 302, null, '/index.php');
        $browser->request('/edit.php?id=1', 302, null, '/case.php?id=1');
        $browser->request('/view_case.php?id=1', 302, null, '/case.php?id=1');
        if ($admin) {
            $json = $browser->request('/ai_dashboard.php', 503, ['csrf'=>field($list, 'csrf')]);
            same(false, json_decode($json, true, flags: JSON_THROW_ON_ERROR)['ok'], 'AI disabled in demo');
        }
        if (!$admin && !$owner) {
            $before = caseAndAuditState($db);
            if ($username !== 'unrelated') {
                $browser->request('/save_case.php', 403, ['action'=>'create_case','case_number'=>'DENIED-CREATE','csrf'=>$csrf]);
                same($before, caseAndAuditState($db), "$username denied create must preserve cases and audit");
            } else {
                $browser->request('/save_case.php', 403, ['action'=>'save_fact','case_id'=>1,'csrf'=>$csrf]);
                same($before, caseAndAuditState($db), 'Former owner cannot change findings or audit');
            }
            $browser->request('/save_case.php', 403, [
                'action'=>'save_page1', 'case_id'=>1, 'case_number'=>'DENIED-EDIT',
                'preliminary_allegation'=>'Denied edit fixture', 'csrf'=>$csrf,
            ]);
            same($before, caseAndAuditState($db), "$username denied edit must preserve cases and audit");
        }
        if ($owner) {
            $editor = $browser;
        } else {
            $browser->request('/logout.php', 302, null, '/login.php');
            $browser->request('/index.php', 302, null, '/login.php');
        }
    }
    return $editor;
}

function removeTemporaryTree(string $directory): void
{
    foreach (new FilesystemIterator($directory) as $file) {
        if ($file->isDir() && !$file->isLink()) removeTemporaryTree($file->getPathname());
        else unlink($file->getPathname());
    }
    rmdir($directory);
}

/** Serve the procedural release over loopback against the supplied synthetic runtime directory. */
function withLegacyServer(string $root, string $temp, callable $check): void
{
    $server = null;
    try {
        // db.php resolves its database relative to __DIR__. Copy only the source the web process needs.
        mkdir($temp.'/tools', 0700);
        foreach (array_merge(glob($root.'/*.php'), [$root.'/tools/dev-login.php', $root.'/tools/router.php']) as $source) {
            if (!copy($source, $temp.substr($source, strlen($root)))) throw new RuntimeException('Could not copy application source');
        }
        $log = ['file', $temp.'/server.log', 'a'];
        $io = [0=>['file','/dev/null','r'], 1=>$log, 2=>$log];
        // Port zero lets the OS allocate a free loopback port even for concurrent runs.
        $server = proc_open([PHP_BINARY, '-d', 'session.save_path='.$temp, '-S', '127.0.0.1:0', 'tools/router.php'],
            $io, $pipes, $temp, array_merge(getenv(), ['DISC2_DEV'=>'1','DISC2_DOCKER'=>'0']));
        $base = null;
        for ($attempt=0; $attempt<100; ++$attempt) {
            if (preg_match('~Development Server \((http://127\.0\.0\.1:[1-9][0-9]*)\) started~', file_get_contents($temp.'/server.log'), $match)) {
                $base = $match[1];
                break;
            }
            if (!proc_get_status($server)['running']) break;
            usleep(20000);
        }
        if (!$base) throw new RuntimeException('Synthetic HTTP server failed to start');
        $check($base);
    } finally {
        if (is_resource($server)) {
            proc_terminate($server);
            proc_close($server);
        }
    }
}

function runLegacy(string $root): void
{
    $temp = sys_get_temp_dir().'/disc2-legacy-'.bin2hex(random_bytes(12));
    if (!mkdir($temp, 0700)) throw new RuntimeException('Cannot create private test directory');
    try {
        $log = ['file', $temp.'/init.log', 'a'];
        $io = [0=>['file','/dev/null','r'], 1=>$log, 2=>$log];
        $init = proc_open([PHP_BINARY, $root.'/tools/init.php', $temp.'/runtime'], $io, $pipes, $root);
        same(0, proc_close($init), 'Synthetic initializer (see tools/init.php)');
        $db = new PDO('sqlite:'.$temp.'/runtime/disc2-dev.sqlite', options: [
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        ]);
        $db->exec('PRAGMA foreign_keys=ON');
        $accounts = seedRoles($db, json_decode(file_get_contents($temp.'/runtime/demo-accounts.json'), true, flags: JSON_THROW_ON_ERROR));

        withLegacyServer($root, $temp, function (string $base) use ($db, $accounts) {
            $editor = checkRoles($db, $base, $accounts);
            echo "PASS role, scope, identity, aliases and status contracts\n";
            $fixtures = require dirname(__DIR__).'/Fixtures/legacy-workflows.php';
            checkWorkflows($editor->request(...), $db, $fixtures);
            same('ok', $db->query('PRAGMA integrity_check')->fetchColumn(), 'Database integrity');
            same([], $db->query('PRAGMA foreign_key_check')->fetchAll(), 'Foreign keys');
            echo "PASS workflow dates, results, escalation, history and database integrity\n";
        });
    } finally {
        removeTemporaryTree($temp);
    }
}

function trackId(PDO $db, string $type, int $set = 1): int
{
    $q = $db->prepare('SELECT id FROM uat_tracks WHERE case_id=1 AND track_type=? AND set_no=?');
    $q->execute([$type, $set]);
    $id = $q->fetchColumn();
    if ($id === false) throw new RuntimeException("Missing $type track, set $set");
    return (int)$id;
}

function trackPath(string $type, int $id, string $page = 'track'): string
{
    return "/$page.php?case_id=1&type=$type&track_id=$id";
}

function datesInDb(PDO $db, int $id): array
{
    return $db->query("SELECT start_date,duration_days,due_date FROM uat_tracks WHERE id=$id")->fetch(PDO::FETCH_NUM);
}

function extensionDates(PDO $db, int $id): array
{
    return $db->query("SELECT new_due_date FROM uat_extensions WHERE track_id=$id ORDER BY sequence_no")->fetchAll(PDO::FETCH_COLUMN);
}

function checkWorkflows(Closure $request, PDO $db, array $fixture): void
{
    $factForm = $request('/case.php?id=1&page=2', 200);
    containsText($factForm, 'method="post" action="/save_case.php"', true, 'Fact form target');
    foreach (['case_id','acknowledged_date','first_meeting_date','duration_days','finding[1]','finding[2]'] as $name) {
        containsText($factForm, 'name="'.$name.'"', true, "Fact form field $name");
    }
    $csrf = field($factForm, 'csrf');
    $form = ['csrf'=>$csrf, 'case_id'=>1];
    $post = function (string $action, array $fields, int $status, ?string $location = null) use ($request, $form): void {
        $request('/save_case.php', $status, $form + ['action'=>$action] + $fields, $location);
    };
    $tab = fn(int $page) => "/case.php?id=1&page=$page&saved=1";
    $request('/save_case.php', 405);
    $request('/save_case.php', 419, ['action'=>'save_fact','case_id'=>1]);
    $post('unknown', [], 400);
    $request('/save_comment2.php', 422, $form + ['action'=>'unknown']);
    $post('save_fact', ['duration_days'=>0], 422);
    foreach ($fixture['dates']['fact'] as $name => [$fields,$expected]) {
        $post('save_fact', $fields + ['finding'=>[1=>'ground',2=>'ground']], 302, $tab(2));
        same($expected, datesInDb($db, trackId($db,'fact')), "Fact $name");
    }
    $type = 'discipline_nonserious';
    $post('assign_discipline', ['track_for'=>[1=>$type,2=>$type]], 302, $tab(3));
    $source = trackId($db, $type);
    $sourcePath = trackPath($type, $source);
    $round = $fixture['round'];
    foreach ($fixture['dates'][$type] as $name => [$fields,$expected]) {
        $post('save_track', $fields + ['track_id'=>$source,'track_type'=>$type], 302, $sourcePath.'&saved=1');
        same($expected, datesInDb($db, $source), "Non-serious $name");
    }
    $post('add_order', ['track_id'=>$source,'event_type'=>'appoint','order_number'=>$round['original_order']], 302, $sourcePath.'&saved=1');
    $post('add_extension', ['track_id'=>$source] + $round['extension'], 302, $sourcePath.'&saved=1');
    same([$round['extended_due']], extensionDates($db, $source), 'Non-serious extension');

    $request('/save_discipline.php', 405);
    foreach ($round['old_results'] as $sid=>$result) {
        $request('/save_discipline.php', 302, $form + ['track_id'=>$source,'subject_id'=>$sid] + $result,
            trackPath($type, $source, 'discipline_results').'&saved=1');
    }
    $post('save_decision', ['subject_id'=>1,'track_id'=>$source,'order_number'=>$round['old_decision']], 302, $tab(4));

    // Seed historical records that the current forms read but cannot create.
    $db->prepare('INSERT INTO uat_kk_reports(decision_id,sequence_no,letter_number) SELECT id,1,? FROM uat_decisions WHERE subject_id=1 AND track_id=?')
        ->execute([$round['old_report'],$source]);
    $db->prepare('INSERT INTO uat_committee_sets(track_id,set_code,order_number) VALUES(?,?,?)')
        ->execute([$source,'DEMO-OLD',$round['original_order']]);
    $committee = (int)$db->lastInsertId();
    $members = $db->prepare('INSERT INTO uat_committee_set_subjects(committee_set_id,subject_id) VALUES(?,?)');
    foreach ([1,2] as $sid) $members->execute([$committee,$sid]);

    $serious = 'discipline_serious';
    $db->prepare('INSERT INTO uat_tracks(case_id,track_type,set_no,duration_days) VALUES(1,?,1,180),(1,?,2,120)')->execute([$serious,$serious]);
    $target = trackId($db, $serious, 2);
    $targetPath = trackPath($serious, $target);
    $post('add_order', ['track_id'=>$target,'event_type'=>'appoint','order_number'=>$round['selected_order']], 302, $targetPath.'&saved=1');
    $results = $request(trackPath($type,$source,'discipline_results'), 200);
    containsText($results, 'method="post" action="/save_discipline.php"', true, 'Discipline result form target');
    $html = new DOMDocument();
    $html->loadHTML($results, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
    $xpath = new DOMXPath($html);
    $options = $xpath->query('//form[input[@name="action" and @value="escalate_discipline"] and input[@name="subject_id" and @value="1"]]//select[@name="target_track"]/option');
    $orderChoices = [];
    foreach ($options as $option) {
        if (str_ends_with(trim($option->textContent), ' · '.$round['selected_order'])) {
            $orderChoices[] = $option->getAttribute('value');
        }
    }
    same([(string)$target], $orderChoices, 'Selected appointment label maps to its track');
    $escalate = ['subject_id'=>1,'track_id'=>$source,'target_track'=>$orderChoices[0],
        'effective_date'=>$round['effective_date'],'reference'=>$round['reference']];
    // Symfony binds each displayed appointment to its revision. Procedural forms predate that field.
    $revision = $xpath->query('//form[input[@name="subject_id" and @value="1"]]//input[@name="target_revision['.$target.']"]')->item(0);
    if ($revision !== null) {
        $escalate['target_revision'] = [$target=>$revision->getAttribute('value')];
    }
    $post('escalate_discipline', $escalate, 302, $targetPath.'&saved=1');
    $post('escalate_discipline', $escalate, 302, $targetPath.'&saved=1');
    same(1, $db->query('SELECT COUNT(*) FROM uat_escalations')->fetchColumn(), 'Repeat escalation');
    $changed = $db->query('SELECT * FROM uat_escalations')->fetch();
    same([1,$source,$target,$round['effective_date'],$round['reference']],
        [$changed['subject_id'],$changed['from_track_id'],$changed['to_track_id'],$changed['effective_date'],$changed['reference']], 'Escalation evidence');
    $snapshot = json_decode($changed['snapshot'], true, flags: JSON_THROW_ON_ERROR);
    same($source, $snapshot['track']['id'], 'Original round');
    same($round['original_order'], $snapshot['orders'][0]['order_number'], 'Original appointment');
    same($round['extended_due'], $snapshot['extensions'][0]['new_due_date'], 'Original extension');
    same($round['old_results'][1]['committee_opinion'], $snapshot['finding']['committee_opinion'], 'Original committee opinion');
    same($round['old_decision'], $snapshot['decisions'][0]['order_number'], 'Original decision');
    same($round['old_report'], $snapshot['decisions'][0]['reports'][0]['letter_number'], 'Original report');
    same($round['original_order'], $snapshot['committee_sets'][0]['order_number'], 'Historical committee set');
    $historicalMembers = array_column($snapshot['committee_members'],'subject_id');
    sort($historicalMembers);
    same([1,2], $historicalMembers, 'Historical committee membership');
    same([[1,0],[2,1]], $db->query("SELECT subject_id,ended_at IS NULL FROM uat_committee_set_subjects WHERE committee_set_id=$committee ORDER BY subject_id")->fetchAll(PDO::FETCH_NUM), 'Only transferred committee membership ended');
    same([[1,$target],[2,$source]], $db->query("SELECT subject_id,track_id FROM uat_track_subjects WHERE track_id IN ($source,$target) AND ended_at IS NULL ORDER BY subject_id")->fetchAll(PDO::FETCH_NUM), 'Current round per person');

    foreach ($fixture['dates'][$serious] as $name => [$fields,$expected]) {
        $post('save_track', $fields + ['track_id'=>$target,'track_type'=>$serious], 302, $targetPath.'&saved=1');
        same($expected, datesInDb($db, $target), "Serious $name");
    }
    $request('/save_discipline.php', 409, $form + ['subject_id'=>1,'track_id'=>$source] + $round['new_result']);
    $request('/save_discipline.php', 302, $form + ['subject_id'=>1,'track_id'=>$target] + $round['new_result'],
        trackPath($serious,$target,'discipline_results').'&saved=1');
    $findings = $db->query("SELECT track_id,subject_id,finding FROM uat_subject_findings WHERE track_id IN ($source,$target) ORDER BY track_id,subject_id")->fetchAll(PDO::FETCH_NUM);
    same([[$source,1,$round['old_results'][1]['finding']],[$source,2,$round['old_results'][2]['finding']],[$target,1,$round['new_result']['finding']]], $findings, 'Per-person results across rounds');
    $audit = $db->query("SELECT detail FROM uat_audit_log WHERE action='escalate_discipline'")->fetchAll(PDO::FETCH_COLUMN);
    same(1, count($audit), 'One escalation audit');
    same(['subject_id'=>1,'before'=>$source,'after'=>$target,'date'=>$round['effective_date'],'reference'=>$round['reference']],
        json_decode($audit[0], true, flags: JSON_THROW_ON_ERROR), 'Escalation audit fields');

    checkConversion($db, $request, $post, $fixture['legacy_conversion']);
}

function checkConversion(PDO $db, Closure $request, Closure $post, array $fixture): void
{
    $type = 'discipline_serious';
    $id = trackId($db, $type);
    $path = trackPath($type,$id);
    $track = ['track_id'=>$id,'track_type'=>$type];
    $post('save_track', $track + $fixture['dates'] + ['duration_days'=>120], 302, $path.'&saved=1');
    same($fixture['old'], datesInDb($db, $id), 'Ordinary save keeps 180 days');
    $stale = field($request($path, 200), 'revision');
    foreach ($fixture['extensions'] as $extension) {
        $post('add_extension', ['track_id'=>$id,'requested_days'=>$extension['days'],'reason'=>$extension['reason']], 302, $path.'&saved=1');
    }
    $oldDue = array_column($fixture['extensions'],'before');
    $newDue = array_column($fixture['extensions'],'after');
    same($oldDue, extensionDates($db,$id), 'Original extension history');
    $revision = field($request($path, 200), 'revision');
    $post('convert_serious_deadline', $track + ['revision'=>$stale,'reason'=>$fixture['reason']], 409);
    $post('convert_serious_deadline', $track + ['revision'=>$revision,'reason'=>' '], 422);
    same($fixture['old'], datesInDb($db,$id), 'Rejected confirmation keeps deadline');
    same($oldDue, extensionDates($db,$id), 'Rejected confirmation keeps extensions');
    $confirmed = $track + ['revision'=>$revision,'reason'=>$fixture['reason']];
    $post('convert_serious_deadline', $confirmed, 302, $path.'&saved=1');
    $post('convert_serious_deadline', $confirmed, 409);
    same($fixture['confirmed'], datesInDb($db,$id), 'Confirmed 120-day deadline');
    same($newDue, extensionDates($db,$id), 'Rebased extension history');
    $audit = $db->query("SELECT detail FROM uat_audit_log WHERE action='convert_serious_deadline'")->fetchAll(PDO::FETCH_COLUMN);
    same(1, count($audit), 'One confirmed conversion');
    $detail = json_decode($audit[0], true, flags: JSON_THROW_ON_ERROR);
    same($fixture['reason'], $detail['reason'], 'Conversion reason in audit');
    same($fixture['old'][2], $detail['before']['due_date'], 'Original due date in audit');
    same($fixture['confirmed'][2], $detail['after']['due_date'], 'Converted due date in audit');
    same($oldDue, array_column($detail['extensions_before'],'new_due_date'), 'Old extensions in audit');
    same($newDue, array_column($detail['extensions_after'],'new_due_date'), 'New extensions in audit');
    same(array_column($fixture['extensions'],'reason'), array_column($detail['extensions_after'],'reason'), 'Extension reasons retained');
}

if (realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    $root = $argv[1] ?? getenv('DISC2_LEGACY_ROOT');
    if (!$root || !is_file($root.'/auth.php')) {
        fwrite(STDERR, "Supply the exported procedural release directory or set DISC2_LEGACY_ROOT.\n");
        exit(2);
    }
    checkInventory($root);
    echo "PASS root PHP inventory\n";
    runLegacy($root);
}
