// StreamFlix — Videoplayer
(function(){
  const video = document.getElementById('video');
  const controls = document.getElementById('controls');
  const playBtn = document.getElementById('playBtn');
  const playedBar = document.getElementById('playedBar');
  const loadedBar = document.getElementById('loadedBar');
  const handle = document.getElementById('handle');
  const curTime = document.getElementById('curTime');
  const durTime = document.getElementById('durTime');
  const volumeInput = document.getElementById('volume');
  const fullscreenBtn = document.getElementById('fullscreenBtn');
  const speedBtn = document.getElementById('speedBtn');
  const subtitleBtn = document.getElementById('subtitleBtn');
  const skipIntroBtn = document.getElementById('skipIntroBtn');
  const config = window.PLAYER_CONFIG || {};
  const profileId = null; // wird vom Server gesetzt (siehe watch.php)

  let progressTimer = null;
  let isSeeking = false;
  let hideTimer = null;

  if (!video) return;

  // Quelle setzen (Direktwiedergabe mit Range-Requests)
  if (config.file) {
    video.src = '/api/play.php?action=stream&type=' + config.type + '&id=' + config.id;
  }
  if (config.progress) {
    video.currentTime = config.progress;
  }

  video.volume = 1;

  function fmt(t){
    const h = Math.floor(t/3600);
    const m = Math.floor((t%3600)/60);
    const s = Math.floor(t%60);
    return h ? h+':'+String(m).padStart(2,'0')+':'+String(s).padStart(2,'0') : m+':'+String(s).padStart(2,'0');
  }

  function togglePlay(){
    if (video.paused) video.play(); else video.pause();
    updatePlayBtn();
  }

  function updatePlayBtn(){
    playBtn.textContent = video.paused ? '▶' : '⏸';
  }

  function updateProgress(){
    if (isSeeking) return;
    const pct = video.duration ? (video.currentTime / video.duration) * 100 : 0;
    playedBar.style.width = pct + '%';
    handle.style.left = pct + '%';
    curTime.textContent = fmt(video.currentTime);
    durTime.textContent = fmt(video.duration);
    // Fortschritt alle 10s speichern
    if (video.currentTime > 0) {
      clearTimeout(progressTimer);
      progressTimer = setTimeout(saveProgress, 8000);
    }
  }

  function saveProgress(){
    fetch('/api/play.php?action=progress', {
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify({type: config.type, id: config.id, position: Math.floor(video.currentTime), duration: Math.floor(video.duration || 0)})
    }).catch(()=>{});
  }

  function seek(e){
    const rect = playedBar.parentElement.getBoundingClientRect();
    const pct = Math.min(1, Math.max(0, (e.clientX - rect.left) / rect.width));
    isSeeking = true;
    video.currentTime = pct * video.duration;
    setTimeout(()=>{ isSeeking = false; }, 200);
  }

  function toggleControls(){
    controls.style.opacity = controls.style.opacity === '0' ? '1' : '0';
  }

  function toggleFullscreen(){
    if (video.requestFullscreen) video.requestFullscreen();
    else if (video.webkitRequestFullscreen) video.webkitRequestFullscreen();
  }

  function changeSpeed(){
    const speeds = [0.5, 1, 1.5, 2];
    let cur = speeds.indexOf(video.playbackRate);
    cur = (cur + 1) % speeds.length;
    video.playbackRate = speeds[cur];
    speedBtn.textContent = speeds[cur] + '×';
    setTimeout(()=> speedBtn.textContent = '1×', 1200);
  }

  function toggleMute(){
    video.muted = !video.muted;
  }

  // Event-Listener
  video.addEventListener('play', updatePlayBtn);
  video.addEventListener('pause', updatePlayBtn);
  video.addEventListener('timeupdate', updateProgress);
  video.addEventListener('loadedmetadata', ()=>{ durTime.textContent = fmt(video.duration); });
  video.addEventListener('progress', ()=>{
    if (video.buffered.length) {
      const pct = (video.buffered.end(0) / video.duration) * 100;
      loadedBar.style.width = pct + '%';
    }
  });
  video.addEventListener('click', togglePlay);
  playBtn.addEventListener('click', togglePlay);
  fullscreenBtn.addEventListener('click', toggleFullscreen);
  speedBtn.addEventListener('click', changeSpeed);
  volumeInput.addEventListener('input', ()=>{ video.volume = volumeInput.value; });
  playedBar.parentElement.addEventListener('click', seek);
  video.addEventListener('mouseenter', ()=>{ controls.style.opacity='1'; });
  video.addEventListener('mouseleave', ()=>{ if (!video.paused) controls.style.opacity='0'; });
  controls.addEventListener('mouseenter', ()=>{ controls.style.opacity='1'; });
  controls.addEventListener('mouseleave', ()=>{ if (!video.paused) controls.style.opacity='0'; });

  // Tastatur
  document.addEventListener('keydown', (e)=>{
    if (e.code === 'Space') { e.preventDefault(); togglePlay(); }
    if (e.code === 'ArrowLeft') { video.currentTime = Math.max(0, video.currentTime - 10); }
    if (e.code === 'ArrowRight') { video.currentTime = Math.min(video.duration, video.currentTime + 10); }
    if (e.code === 'KeyF') toggleFullscreen();
    if (e.code === 'KeyM') toggleMute();
  });

  // Intro überspringen (Beispiel: bei 10% der Dauer einblenden, hier Platzhalter)
  if (skipIntroBtn && config.duration) {
    const skipAt = Math.min(120, config.duration * 0.15);
    video.addEventListener('timeupdate', ()=>{
      if (video.currentTime >= skipAt && video.currentTime <= skipAt + 2) {
        skipIntroBtn.classList.remove('hidden');
      } else {
        skipIntroBtn.classList.add('hidden');
      }
    });
    skipIntroBtn.addEventListener('click', ()=>{ video.currentTime = skipAt + 5; });
  }

  // Automatische Wiedergabe versuchen
  video.play().catch(()=>{});
})();
