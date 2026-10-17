<?php
// emergency hotlines shown on hotlines.php
// numbers copied from the official Barangay 172 Zone 17 emergency hotline sign (photo from our system analyst, Oct 2026)
// dont put guessed numbers here, wrong hotlines are worse than none during an emergency
//
// format, one per hotline (one number per card so the tap-to-call works):
//   ['name' => 'Barangay 172 Hall', 'number' => '09xxxxxxxxx', 'note' => 'Open 24/7'],
//
// order matters, put the barangay first since thats who the system reports to
//
// not added yet, hard to read on the sign, confirm with the barangay first:
//   Pasay City Police Sub Station 6 mobile (looks like 0927 699 3932)
//   City Health Office 2nd number (looks like 098 993 7024, only 10 digits so something is missing)

return [
    // barangay first
    ['name' => 'Barangay 172 Hotline', 'number' => '0962 543 0752', 'note' => 'Smart · 830 I. Santos St., Malibay'],
    ['name' => 'Barangay 172 Hotline', 'number' => '0966 235 0677', 'note' => 'Globe · 830 I. Santos St., Malibay'],

    // disaster and rescue
    ['name' => 'Pasay City DRRMO (Rescue)', 'number' => '(02) 8551 7777', 'note' => 'Disaster Risk Reduction & Management'],
    ['name' => 'Pasay City DRRMO (Rescue)', 'number' => '(02) 8833 8521', 'note' => 'Disaster Risk Reduction & Management'],
    ['name' => 'Pasay City DRRMO (Rescue)', 'number' => '0905 493 9111', 'note' => 'Mobile'],

    // fire
    ['name' => 'Bureau of Fire Protection (BFP Pasay)', 'number' => '(02) 8844 2120', 'note' => 'Fire'],
    ['name' => 'Bureau of Fire Protection (BFP Pasay)', 'number' => '(02) 8843 6523', 'note' => 'Fire'],

    // police
    ['name' => 'Pasay Philippine National Police', 'number' => '0956 800 5277', 'note' => 'Police'],
    ['name' => 'Pasay City Police Sub Station 6', 'number' => '(02) 8364 0651', 'note' => 'Police'],

    // medical
    ['name' => 'Pasay City General Hospital', 'number' => '(02) 8551 0121', 'note' => 'Hospital'],
    ['name' => 'Pasay City Health Office', 'number' => '0956 778 6524', 'note' => 'Health'],

    // traffic and accidents
    ['name' => 'Pasay City Traffic', 'number' => '(02) 8257 9914', 'note' => 'Road accidents and traffic'],
    ['name' => 'Pasay City Traffic', 'number' => '0961 188 9914', 'note' => 'Mobile'],

    // utilities (fallen power lines, water)
    ['name' => 'Meralco', 'number' => '16211', 'note' => 'Power outages and fallen lines'],
    ['name' => 'Maynilad', 'number' => '0998 864 1446', 'note' => 'Water'],
];
