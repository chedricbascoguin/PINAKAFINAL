(() => {
  const video = document.getElementById('qr-video');
  const canvas = document.getElementById('qr-canvas');
  const context = canvas.getContext('2d', {willReadFrequently: true});
  const status = document.getElementById('scan-status');
  const start = document.getElementById('start-camera');
  const stopButton = document.getElementById('stop-camera');
  let stream = null, timer = null, opening = false;
  let lastCode = '', repeatCount = 0, lastDetection = 0;
  function stop() {
    clearTimeout(timer);
    stream?.getTracks().forEach(track => track.stop());
    stream = null;
    video.srcObject = null;
    video.hidden = true;
    start.disabled = false;
    stopButton.hidden = true;
  }
  function openCode(value) {
    try {
      const url = new URL(value, location.href);
      const expected = new URL('StaffResidentAppointments.php', location.href);
      const id = url.searchParams.get('resident_id');
      const hostname = url.hostname.replace(/^www\./i, '').toLowerCase();
      const currentHost = expected.hostname.replace(/^www\./i, '').toLowerCase();
      const officialHost = ['rhu.page.gd','ruralhealthunit.page.gd','resihunitynasugbu.online','resihunitynasugbu.site'].includes(hostname);
      const validAddress = (hostname === currentHost && url.pathname === expected.pathname)
        || (officialHost && ['/StaffResidentAppointments.php','/CAPSTONERHU/StaffResidentAppointments.php'].includes(url.pathname));
      if (!['http:','https:'].includes(url.protocol) || url.username || url.password || !validAddress || !/^[1-9]\d*$/.test(id || '')) throw new Error();
      stop();
      if (!opening) {
        opening = true;
        expected.searchParams.set('resident_id', id);
        if (new URL(location.href).searchParams.get('embed') === '1') expected.searchParams.set('embed', '1');
        location.assign(expected.href);
      }
      return true;
    } catch { status.textContent = 'QR code not recognized. Open My Profile on the resident account and scan the QR code on My Resident ID.'; return false; }
  }
  function decode(source, width, height) {
    const scale = Math.min(1, 1200 / Math.max(width, height));
    canvas.width = Math.round(width * scale); canvas.height = Math.round(height * scale);
    context.drawImage(source, 0, 0, canvas.width, canvas.height);
    const pixels = context.getImageData(0, 0, canvas.width, canvas.height);
    return jsQR(pixels.data, pixels.width, pixels.height);
  }
  function tick() {
    if (!stream) return;
    if (video.readyState >= 2 && video.videoWidth) {
      const result = decode(video, video.videoWidth, video.videoHeight);
      if (result) {
        lastDetection = Date.now();
        repeatCount = result.data === lastCode ? repeatCount + 1 : 1;
        lastCode = result.data;
        // Confirm the same code across frames before accepting or rejecting it.
        if (repeatCount >= 2 && openCode(result.data)) return;
      } else {
        lastCode = ''; repeatCount = 0;
        if (Date.now() - lastDetection > 1500) status.textContent = 'Point the camera at the QR code on the resident ID. Waiting for a clear QR code…';
      }
    }
    timer = setTimeout(tick, 200);
  }
  start.addEventListener('click', async () => {
    start.disabled = true;
    lastCode = ''; repeatCount = 0; lastDetection = 0;
    try {
      if (!navigator.mediaDevices?.getUserMedia) throw new Error();
      stream = await navigator.mediaDevices.getUserMedia({video:{facingMode:{ideal:'environment'}},audio:false});
      video.srcObject = stream; video.hidden = false; stopButton.hidden = false;
      await video.play();
      status.textContent = 'Hold the resident QR code steady inside the camera view.';
      tick();
    } catch { stop(); status.textContent = 'Camera unavailable. Allow camera access and open this portal over HTTPS or localhost, then try again.'; }
  });
  stopButton.addEventListener('click', () => {stop(); status.textContent = 'Camera stopped.';});
  document.addEventListener('click', event => {
    const appointment = event.target.closest('.appointment-link');
    if (!appointment || window.parent === window) return;
    event.preventDefault();
    stop();
    // Ask the dashboard hosting this scanner to navigate itself. _top can
    // target an outer preview/app frame instead of the staff dashboard.
    window.parent.postMessage({type:'resident-qr-open',url:appointment.href}, location.origin);
  });
  window.addEventListener('pagehide', stop);
  document.addEventListener('visibilitychange', () => { if (document.hidden) stop(); });
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && window.parent !== window) {
      stop();
      window.parent.postMessage('resident-qr-close', location.origin);
    }
  });
})();
