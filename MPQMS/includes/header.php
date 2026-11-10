<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <?php if(!empty($_SESSION['csrf'])): ?><meta name="csrf-token" content="<?=htmlspecialchars($_SESSION['csrf'], ENT_QUOTES)?>"><?php endif; ?>
        <title>MPQMS | Medicinal Products Quality Monitoring System</title>
    </head>
