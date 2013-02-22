/**
 * Javascript functionality for the discussion plugin
 */

/**
 * Check if a field is blank
 */
function isBlank(s){
  if ((s === null) || (s.length === 0)){
    return true;
  }

  for (var i = 0; i < s.length; i++){
    var c = s.charAt(i);
	  if ((c != ' ') && (c != '\n') && (c != '\t')){
	    return false;
    }
  }
  return true;
}

/**
 * Validate an input field
 */
function validate(form){
    if(!form) return;

    if (isBlank(form.name.value)){
        form.name.focus();
        form.name.style.backgroundColor = '#fcc';
        return false;
    } else {
        form.name.style.backgroundColor = '#fff';
    }
    if (isBlank(form.mail.value) || form.mail.value.indexOf("@") == -1){
        form.mail.focus();
        form.mail.style.backgroundColor = '#fcc';
        return false;
    } else {
        form.mail.style.backgroundColor = '#fff';
    }
    if (isBlank(form.text.value)){
        form.text.focus();
        form.text.style.borderColor = '#fcc';
        return false;
    }
}

/**
 * AJAX preview
 *
 * @author Michael Klier <chi@chimeric.de>
 */
function discussion_ajax_preview() {
    var $textarea = jQuery('#discussion__comment_text');
    var comment = $textarea.val();

    var $preview = jQuery('#discussion__comment_preview');
    if (!comment) {
        $preview.hide();
        return;
    }
    $preview.html('<img src="'+DOKU_BASE+'/lib/images/throbber.gif" />');
    $preview.show();

    jQuery.post(DOKU_BASE + 'lib/exe/ajax.php',
        {
            'call': 'discussion_preview',
            'comment': comment
        },
        function (data) {
            if (data === '') {
                $preview.hide();
                return;
            }
            $preview.html(data);
            $preview.show();
            $preview.css('visibility', 'visible');
        }, 'html');
}

jQuery(function() {
    // init toolbar
    if(typeof window.initToolbar == 'function') {
        initToolbar("discussion__comment_toolbar", "discussion__comment_text", toolbar);
    }

    // init preview button
    jQuery('#discussion__btn_preview').click(discussion_ajax_preview);

    // init field check
    jQuery('#discussion__comment_form').submit(function() { return validate(this); });

    // toggle section visibility
    jQuery('#discussion__btn_toggle_visibility').click(function() {
        jQuery('#comment_wrapper').toggle();
    });
});
