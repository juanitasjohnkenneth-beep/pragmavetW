-- PragmaVet Smart Scheduling Migration
CREATE TABLE IF NOT EXISTS clinic_holidays (
    id INT AUTO_INCREMENT PRIMARY KEY,
    holiday_date DATE NOT NULL,
    holiday_name VARCHAR(150) NOT NULL,
    holiday_type ENUM('Regular','Special Non-Working','Special Working') DEFAULT 'Regular',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_holiday_date (holiday_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS doctor_schedules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    doctor_id INT NOT NULL,
    work_start TIME NOT NULL DEFAULT '08:00:00',
    work_end TIME NOT NULL DEFAULT '17:00:00',
    break_start TIME DEFAULT '12:00:00',
    break_end TIME DEFAULT '13:00:00',
    buffer_mins INT NOT NULL DEFAULT 15,
    max_appts_per_day INT NOT NULL DEFAULT 10,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_doctor (doctor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO clinic_holidays (holiday_date, holiday_name, holiday_type) VALUES
('2026-01-01','New Years Day','Regular'),
('2026-02-25','EDSA People Power Anniversary','Special Non-Working'),
('2026-04-02','Holy Thursday','Regular'),
('2026-04-03','Good Friday','Regular'),
('2026-04-04','Black Saturday','Special Non-Working'),
('2026-04-09','Day of Valor','Regular'),
('2026-05-01','Labor Day','Regular'),
('2026-06-12','Independence Day','Regular'),
('2026-08-21','Ninoy Aquino Day','Special Non-Working'),
('2026-08-31','National Heroes Day','Regular'),
('2026-11-01','All Saints Day','Special Non-Working'),
('2026-11-02','All Souls Day','Special Non-Working'),
('2026-11-30','Bonifacio Day','Regular'),
('2026-12-08','Feast of the Immaculate Conception','Special Non-Working'),
('2026-12-24','Christmas Eve','Special Non-Working'),
('2026-12-25','Christmas Day','Regular'),
('2026-12-30','Rizal Day','Regular'),
('2026-12-31','Last Day of the Year','Special Non-Working'),
('2027-01-01','New Years Day','Regular'),
('2027-04-01','Holy Thursday','Regular'),
('2027-04-02','Good Friday','Regular'),
('2027-04-03','Black Saturday','Special Non-Working'),
('2027-04-09','Day of Valor','Regular'),
('2027-05-01','Labor Day','Regular'),
('2027-06-12','Independence Day','Regular'),
('2027-08-21','Ninoy Aquino Day','Special Non-Working'),
('2027-08-30','National Heroes Day','Regular'),
('2027-11-01','All Saints Day','Special Non-Working'),
('2027-11-30','Bonifacio Day','Regular'),
('2027-12-08','Feast of the Immaculate Conception','Special Non-Working'),
('2027-12-24','Christmas Eve','Special Non-Working'),
('2027-12-25','Christmas Day','Regular'),
('2027-12-30','Rizal Day','Regular'),
('2027-12-31','Last Day of the Year','Special Non-Working');

INSERT IGNORE INTO doctor_schedules (doctor_id, work_start, work_end, break_start, break_end, buffer_mins, max_appts_per_day)
SELECT id, '08:00:00', '17:00:00', '12:00:00', '13:00:00', 15, 10
FROM users WHERE role = 'doctor';
