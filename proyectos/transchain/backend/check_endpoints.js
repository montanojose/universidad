(async function(){
  const urls = [
    '/api/hedera/status',
    '/api/dashboard',
    '/api/donantes'
  ];
  const base = 'http://localhost:3000';
  for (const path of urls) {
    try {
      const res = await fetch(base + path);
      const text = await res.text();
      console.log(`=== ${path} ===`);
      console.log(text);
    } catch (err) {
      console.log(`=== ${path} ===`);
      console.log('ERROR:', err.message);
    }
    console.log('\n');
  }
})();
