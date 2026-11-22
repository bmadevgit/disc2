-- Legacy table definitions for a fresh development database.

CREATE TABLE cases (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    จำนวนราย TEXT,
    เลขที่สำนวน TEXT,
    เลขที่หนังสือ TEXT,
    วันที่ส่งเรื่อง TEXT,
    กวส_รับเรื่อง TEXT,
    ตำแหน่ง TEXT,
    ประเภทข้าราชการ TEXT,
    หน่วยงานต้นเรื่อง TEXT,
    ประเภทสำนวน TEXT,
    ฐานความผิด TEXT,
    ความผิดเกี่ยวกับ TEXT,
    ลักษณะการกระทำ TEXT,
    ระดับโทษที่เสนอ TEXT,
    สถานะข้าราชการ TEXT,
    วันที่พ้นจากราชการ TEXT,
    วันที่เกษียณ TEXT,
    สถานะสำนวน TEXT,
    เข้าประชุม_อกก_วินัย TEXT,
    เข้าประชุม_กก TEXT,
    เจ้าหน้าที่รับผิดชอบ TEXT,
    หมายเหตุ TEXT,
    สาเหตุพ้นราชการ TEXT,
    ระดับโทษที่พิจารณา TEXT,
    วันที่เกิดเหตุ TEXT,
    วันที่แต่งตั้งคกก_สืบ1 TEXT,
    วันที่แต่งตั้งคกก_สืบ2 TEXT,
    วันที่แต่งตั้งคกก_สอบ TEXT,
    วันแก้ไขคำสั่งสอบ1 TEXT,
    วันแก้ไขคำสั่งสอบ2 TEXT,
    ขยายเวลา1 TEXT,
    ขยายวัน1 TEXT,
    เหตุผลขยาย1 TEXT,
    ขยายเวลา2 TEXT,
    ขยายวัน2 TEXT,
    เหตุผลขยาย2 TEXT,
    ขยายเวลา3 TEXT,
    ขยายวัน3 TEXT,
    เหตุผลขยาย3 TEXT,
    ขยายเวลา4 TEXT,
    ขยายวัน4 TEXT,
    เหตุผลขยาย4 TEXT,
    ขยายเวลา5 TEXT,
    ขยายวัน5 TEXT,
    เหตุผลขยาย5 TEXT,
    ล่าช้า TEXT,
    เข้าประชุม_อกก1 TEXT,
    เข้าประชุม_อกก2 TEXT,
    เข้าประชุม_กก1 TEXT,
    เข้าประชุม_กก2 TEXT,
    ผลพิจารณา_อกก TEXT,
    ผลพิจารณา_กก TEXT,
    เรื่องเสร็จวันที่ TEXT,
    submitted_by_id TEXT,
    submitted_by_name TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
, ตำแหน่งผู้ถูกกล่าวหา TEXT, หมายเหตุวันที่เกิดเหตุ TEXT, เลขคำสั่งสืบ1 TEXT, เลขคำสั่งสืบ2 TEXT, เลขคำสั่งสอบ TEXT, เข้าประชุม_อกก3 TEXT, เข้าประชุม_อกก4 TEXT, เข้าประชุม_กก3 TEXT, เข้าประชุม_กก4 TEXT, unit_level1 TEXT, ผลการสอบสวน_ผู้บังคับบัญชาลงโทษ TEXT, หน่วยงานรับเรื่อง TEXT, ผลการสืบสวน TEXT, ระดับความร้ายแรง_สืบสวน TEXT, ผลการสอบสวนวินัย TEXT, ระดับความร้ายแรง_สอบสวนวินัย TEXT, รายงานกก_วันที่ TEXT, วันแก้ไขคำสั่งคกก_สืบ1 TEXT, เลขแก้ไขคำสั่งคกก_สืบ1 TEXT, วันที่แต่งตั้งคกก_สอบไม่ร้ายแรง TEXT, เลขคำสั่งคกก_สอบไม่ร้ายแรง TEXT, วันที่แต่งตั้งคกก_สอบร้ายแรง TEXT, เลขคำสั่งคกก_สอบร้ายแรง TEXT, วันแก้ไขคำสั่งคกก_สอบร้ายแรง TEXT, เลขแก้ไขคำสั่งคกก_สอบร้ายแรง TEXT, วันที่แก้ไขคำสั่งคกก_สอบไม่ร้ายแรง TEXT, เลขแก้ไขคำสั่งคกก_สอบไม่ร้ายแรง TEXT);

CREATE TABLE login_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT,
    user_name TEXT,
    user_role TEXT,
    ip TEXT,
    user_agent TEXT,
    status TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE unit_case_targets (
    unit_level1 TEXT PRIMARY KEY,
    target_total INTEGER NOT NULL DEFAULT 0,
    updated_by TEXT,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE user_unit_assignments (
    bma_personal_id TEXT PRIMARY KEY,
    bma_email TEXT NOT NULL,
    unit_level1 TEXT NOT NULL,
    assigned_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT UNIQUE NOT NULL,
    name TEXT NOT NULL,
    password_hash TEXT NOT NULL,
    role TEXT NOT NULL DEFAULT 'user',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_cases_unit_level1 ON cases(unit_level1);
