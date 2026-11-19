<?php
/** Expects $page (title, ...) and $body (already cleaned with RichText). */
?>
<div class="s-wrap page-content" style="max-width:820px;margin:2rem auto;padding:0 1rem">
    <h1><?= e($page['title']) ?></h1>
    <div class="desc"><?= $body ?></div>
</div>
