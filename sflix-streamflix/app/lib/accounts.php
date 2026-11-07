<?php
declare(strict_types=1);

function valid_avatar(string $a): bool { return (bool)preg_match('/^(a[1-8]|img:[a-f0-9]{24}\.(jpg|png|webp))$/', $a); }

function create_profile(int $uid, string $name, string $avatar = 'a1', bool $kids = false): int {
    $name = trim($name);
    if ($name === '' || mb_strlen($name) > 30) fail('Profilname muss 1–30 Zeichen lang sein.');
    if (!valid_avatar($avatar)) $avatar = 'a1';
    q('INSERT INTO profiles(user_id,name,avatar,is_kids) VALUES(?,?,?,?)', [$uid, $name, $avatar, $kids ? 1 : 0]);
    $id = (int)db()->lastInsertId();
    q('INSERT INTO profile_settings(profile_id,subtitles_on) VALUES(?,?)', [$id, setting('default_subtitle', 'off') === 'off' ? 0 : 1]);
    return $id;
}

function set_pin(int $profileId, string $pin): void {
    if ($pin === '') { q('UPDATE profiles SET pin_hash=NULL WHERE id=?', [$profileId]); return; }
    if (!preg_match('/^\d{4}$/', $pin)) fail('Die PIN muss aus genau 4 Ziffern bestehen.');
    q('UPDATE profiles SET pin_hash=? WHERE id=?', [password_hash($pin, PASSWORD_DEFAULT), $profileId]);
}
