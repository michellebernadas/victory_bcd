-- Purple Book Class was sharing the exact Spiritual Foundations purple
-- (#51154B), making the two steps indistinguishable in badges/colors. PBC now
-- uses `purple-light`, a lighter tint of the same purple family, defined in
-- css/style.css alongside the SF purple utilities.
UPDATE `discipleship_steps`
   SET `color` = 'purple-light'
 WHERE `column_key` = 'purple_book_class' AND `color` = 'purple';
