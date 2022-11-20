/**
 * Javascript functionality for the discussion plugin
 */

/**
 * Check if a field is blank
 */
function isBlank(s) {
    if ((s === null) || (s.length === 0)) {
        return true;
    }

    for (let i = 0; i < s.length; i++) {
        let c = s.charAt(i);
        if (c !== ' ' && c !== '\n' && c !== '\t') {
            return false;
        }
    }
    return true;
}

/**
 * Validate an input field
 */
function validate(form) {
    if (!form) return;

    if (isBlank(form.name.value)) {
        form.name.focus();
        form.name.style.backgroundColor = '#fcc';
        return false;
    } else {
        form.name.style.backgroundColor = '#fff';
    }
    if (isBlank(form.mail.value) || form.mail.value.indexOf("@") === -1) {
        form.mail.focus();
        form.mail.style.backgroundColor = '#fcc';
        return false;
    } else {
        form.mail.style.backgroundColor = '#fff';
    }
    if (isBlank(form.text.value)) {
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
    let $textarea = jQuery('#discussion__comment_text');
    let comment = $textarea.val();

    let $preview = jQuery('#discussion__comment_preview');
    if (!comment) {
        $preview.hide();
        return;
    }
    $preview.html('<img src="' + DOKU_BASE + 'lib/images/throbber.gif" />');
    $preview.show();

    jQuery.post(DOKU_BASE + 'lib/exe/ajax.php',
        {
            'call':    'discussion_preview',
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
            $preview.css('display', 'inline');
        }, 'html');
}

jQuery(function () {
    // init toolbar
    if (typeof window.initToolbar == 'function') {
        initToolbar("discussion__comment_toolbar", "discussion__comment_text", toolbar);
    }

    // init preview button
    jQuery('#discussion__btn_preview').on('click', discussion_ajax_preview);

    // init field check
    jQuery('#discussion__comment_form').on('submit', function () {
        return validate(this);
    });

    //confirm delete actions
    jQuery('input.dcs_confirmdelete').on('click', function () {
        return confirm(LANG.plugins.discussion.confirmdelete);
    });

    // toggle section visibility
    jQuery('#discussion__btn_toggle_visibility').on('click', function () {
        jQuery('#comment_wrapper').toggle();
    });
});
