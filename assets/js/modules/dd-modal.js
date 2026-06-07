window.ddAlert = function (msg) {
    var overlay = document.createElement('div');
    Object.assign(overlay.style, {position:'fixed',top:'0',left:'0',right:'0',bottom:'0',background:'rgba(0,0,0,0.5)',zIndex:'99999',display:'flex',alignItems:'center',justifyContent:'center'});
    var box = document.createElement('div');
    Object.assign(box.style, {background:'#fff',borderRadius:'8px',padding:'28px 32px',maxWidth:'420px',width:'90%',boxShadow:'0 4px 24px rgba(0,0,0,0.18)',fontFamily:'inherit'});
    var p = document.createElement('p');
    Object.assign(p.style, {margin:'0 0 20px',fontSize:'15px',lineHeight:'1.5'});
    p.textContent = msg;
    var btn = document.createElement('button');
    btn.type = 'button';
    Object.assign(btn.style, {background:'#1a1a1a',color:'#fff',border:'none',borderRadius:'6px',padding:'10px 24px',fontSize:'14px',cursor:'pointer'});
    btn.textContent = 'OK';
    btn.addEventListener('click', function () { document.body.removeChild(overlay); });
    box.appendChild(p);
    box.appendChild(btn);
    overlay.appendChild(box);
    document.body.appendChild(overlay);
    btn.focus();
};

/**
 * @param {string}   msg
 * @param {function} onOk     Called when the user confirms. If options.keepOpen is set,
 *                            it receives a `close` function to call once processing is done.
 * @param {object}   [options]
 * @param {boolean}  [options.keepOpen]       Don't auto-close on confirm; instead disable the
 *                                            buttons, switch the confirm label to processingText,
 *                                            and leave closing up to the caller via `close()`.
 * @param {string}   [options.processingText] Label shown on the confirm button while keepOpen is active.
 */
window.ddConfirm = function (msg, onOk, options) {
    options = options || {};
    var overlay = document.createElement('div');
    Object.assign(overlay.style, {position:'fixed',top:'0',left:'0',right:'0',bottom:'0',background:'rgba(0,0,0,0.5)',zIndex:'99999',display:'flex',alignItems:'center',justifyContent:'center'});
    var box = document.createElement('div');
    Object.assign(box.style, {background:'#fff',borderRadius:'8px',padding:'28px 32px',maxWidth:'420px',width:'90%',boxShadow:'0 4px 24px rgba(0,0,0,0.18)',fontFamily:'inherit'});
    var p = document.createElement('p');
    Object.assign(p.style, {margin:'0 0 20px',fontSize:'15px',lineHeight:'1.5'});
    p.textContent = msg;
    var row = document.createElement('div');
    Object.assign(row.style, {display:'flex',gap:'12px',justifyContent:'flex-end'});
    var cancelBtn = document.createElement('button');
    cancelBtn.type = 'button';
    Object.assign(cancelBtn.style, {background:'#e5e7eb',color:'#333',border:'none',borderRadius:'6px',padding:'10px 20px',fontSize:'14px',cursor:'pointer'});
    cancelBtn.textContent = 'Cancel';
    var okBtn = document.createElement('button');
    okBtn.type = 'button';
    Object.assign(okBtn.style, {background:'#1a1a1a',color:'#fff',border:'none',borderRadius:'6px',padding:'10px 24px',fontSize:'14px',cursor:'pointer'});
    okBtn.textContent = 'Confirm';
    var close = function () {
        if (overlay.parentNode) {
            document.body.removeChild(overlay);
        }
    };
    cancelBtn.addEventListener('click', close);
    okBtn.addEventListener('click', function () {
        if (options.keepOpen) {
            cancelBtn.disabled = true;
            okBtn.disabled = true;
            okBtn.textContent = options.processingText || 'Processing…';
            Object.assign(okBtn.style, {cursor:'default',opacity:'0.7'});
            onOk(close);
        } else {
            close();
            onOk();
        }
    });
    row.appendChild(cancelBtn);
    row.appendChild(okBtn);
    box.appendChild(p);
    box.appendChild(row);
    overlay.appendChild(box);
    document.body.appendChild(overlay);
    okBtn.focus();
};
