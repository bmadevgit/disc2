<?php
// Fixed synthetic inputs and expected persisted values for the legacy runner and later PHPUnit suite.
// Each deadline row supplies the form fields and [start_date, duration_days, due_date].
return [
    'dates' => [
        'fact' => [
            'equal, default duration' => [
                ['acknowledged_date'=>'2024-02-01', 'first_meeting_date'=>'2024-02-01'],
                ['2024-02-01',30,'2024-03-01'],
            ],
            'different, default duration' => [
                ['acknowledged_date'=>'2024-02-01', 'first_meeting_date'=>'2024-02-05'],
                ['2024-02-02',30,'2024-03-02'],
            ],
            'editable duration' => [
                ['acknowledged_date'=>'2024-02-01', 'first_meeting_date'=>'2024-02-05', 'duration_days'=>40],
                ['2024-02-02',40,'2024-03-12'],
            ],
        ],
        'discipline_nonserious' => [
            'equal' => [
                ['acknowledged_date'=>'2024-02-01', 'first_meeting_date'=>'2024-02-01'],
                ['2024-02-01',60,'2024-03-31'],
            ],
            'different' => [
                ['acknowledged_date'=>'2024-02-01', 'first_meeting_date'=>'2024-02-05'],
                ['2024-02-02',60,'2024-04-01'],
            ],
        ],
        'discipline_serious' => [
            'day after first meeting' => [
                ['acknowledged_date'=>'2024-02-01', 'first_meeting_date'=>'2024-02-05'],
                ['2024-02-06',120,'2024-06-04'],
            ],
        ],
    ],
    'legacy_conversion' => [
        'dates'=>['acknowledged_date'=>'2024-02-01','first_meeting_date'=>'2024-02-05'],
        'old'=>['2024-02-05',180,'2024-08-02'],
        'confirmed'=>['2024-02-06',120,'2024-06-04'],
        'extensions'=>[
            ['days'=>7,'reason'=>'Synthetic review','before'=>'2024-08-09','after'=>'2024-06-11'],
            ['days'=>3,'reason'=>'Synthetic follow-up','before'=>'2024-08-12','after'=>'2024-06-14'],
        ],
        'reason'=>'Confirm the synthetic legacy schedule',
    ],
    'round' => [
        'original_order'=>'DEMO-NON-01', 'selected_order'=>'DEMO-SER-02',
        'reference'=>'DEMO-TRANSFER-01', 'effective_date'=>'2024-04-10',
        'extension'=>['requested_days'=>7,'completed_work'=>'Synthetic interview','reason'=>'Synthetic review'],
        'extended_due'=>'2024-04-08',
        'old_results'=>[
            1=>['finding'=>'guilty','committee_opinion'=>'Synthetic escalation opinion','recommend_serious'=>1],
            2=>['finding'=>'not_guilty','committee_opinion'=>'Synthetic second opinion','recommend_serious'=>0],
        ],
        'new_result'=>['finding'=>'not_guilty','committee_opinion'=>'Synthetic final opinion'],
        'old_decision'=>'DEMO-DECISION-01', 'old_report'=>'DEMO-REPORT-01',
    ],
];
