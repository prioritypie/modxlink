modxlink
========

CKEditor plugin for choosing MODX site page within Add Link dialog

Instructions
------------

Add the modxlink folder to {modx_manager_path}/assets/components/ckeditor/plugins/

In MODX system settings, in CKEditor namespace, add modxlink to ckeditor.extra_plugins and update ckeditor.toolbar to replace Link with "Modxlink", e.g.

<pre>
{ "name": "links", "items": [ "Modxlink", "Unlink", "Anchor"] }
</pre>