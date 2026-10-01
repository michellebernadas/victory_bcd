-- Spiritual Foundations was given its own purple brand color across the UI
-- (dashboard, attendance records, SF module), but discipleship_steps.color
-- was never updated to match — it still said 'info'. Historical Completions
-- reads this column directly as its single color source, so fix the data
-- here rather than duplicating a color map in the view.
UPDATE `discipleship_steps`
   SET `color` = 'purple'
 WHERE `column_key` = 'spiritual_foundations' AND `color` = 'info';
