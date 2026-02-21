<?php
/**
 * Reusable empty state for security section tables.
 * @param string $iconClass  Ki-duotone icon class (e.g. ki-abstract-41)
 * @param string $message    Message to display (e.g. "No records found")
 */
if (!isset($iconClass)) $iconClass = 'ki-abstract-41';
if (!isset($message)) $message = 'No records found';
?>
<div class="text-center py-10">
    <i class="ki-duotone <?= e($iconClass) ?> fs-8x text-gray-400 mb-5">
        <span class="path1"></span>
        <span class="path2"></span>
    </i>
    <div class="fs-4 fw-bold text-gray-400"><?= e($message) ?></div>
</div>
