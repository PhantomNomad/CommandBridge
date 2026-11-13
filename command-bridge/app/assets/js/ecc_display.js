(() => {
  const tabs = Array.from(document.querySelectorAll('[data-ecc-tab]'));
  const panes = Array.from(document.querySelectorAll('[data-ecc-pane]'));
  const activate = (tabName) => {
    tabs.forEach((tab) => tab.classList.toggle('is-active', tab.dataset.eccTab === tabName));
    panes.forEach((pane) => {
      const active = pane.dataset.eccPane === tabName;
      pane.classList.toggle('is-active', active);
      pane.hidden = !active;
    });
    if (history.replaceState) history.replaceState(null, '', `#${tabName}`);
    // Meetings: start at the last meeting held so the upcoming ones are on screen.
    if (tabName === 'meetings') {
      const pane = panes.find((p) => p.dataset.eccPane === 'meetings');
      const lastHeld = pane ? pane.querySelector('[data-ecc-last-held]') : null;
      if (pane && lastHeld) {
        pane.scrollTop = Math.max(0, lastHeld.getBoundingClientRect().top - pane.getBoundingClientRect().top + pane.scrollTop - 12);
      }
    }
  };
  tabs.forEach((tab) => tab.addEventListener('click', () => activate(tab.dataset.eccTab)));
  const requested = window.location.hash.replace('#', '');
  if (requested && tabs.some((tab) => tab.dataset.eccTab === requested)) activate(requested);
  else {
    const shown = panes.find((pane) => !pane.hidden && pane.dataset.eccPane === 'meetings');
    if (shown) activate('meetings');
  }

  const clock = document.querySelector('.ecc-clock');
  const updateClock = () => {
    if (clock) clock.textContent = new Intl.DateTimeFormat('en-CA', {
      weekday: 'short', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', hour12: false
    }).format(new Date());
  };
  updateClock();
  window.setInterval(updateClock, 1000);

  const el = (tag, className, text) => {
    const element = document.createElement(tag);
    if (className) element.className = className;
    if (text !== undefined) element.textContent = text;
    return element;
  };
  const weatherTime = (unix, offset) => {
    const date = new Date((Number(unix) + Number(offset || 0)) * 1000);
    const hour = date.getUTCHours();
    return `${hour % 12 || 12} ${hour >= 12 ? 'PM' : 'AM'}`;
  };
  const weatherIcon = (code, description, className) => {
    const image = el('img', className);
    image.src = `https://openweathermap.org/img/wn/${encodeURIComponent(code || '01d')}@2x.png`;
    image.alt = description || 'Weather condition';
    return image;
  };
  const temperature = (value) => `${Math.round(Number(value))}°`;

  const renderWeather = (panel, weather) => {
    const current = weather.current || {};
    const root = el('div', 'ecc-weather-content');
    const currentBlock = el('div', 'ecc-weather-current');
    const summary = el('div', 'ecc-weather-summary');
    summary.append(el('div', 'ecc-weather-location', weather.location || 'Configured location'));
    summary.append(el('div', 'ecc-weather-condition', current.description || 'Current conditions'));
    const temp = el('div', 'ecc-weather-temperature', temperature(current.temperature));
    currentBlock.append(summary, weatherIcon(current.icon, current.description, 'ecc-weather-current-icon'), temp);

    const metrics = el('div', 'ecc-weather-metrics');
    [['Feels like', temperature(current.feels_like)], ['Wind', `${Math.round(Number(current.wind_kph || 0))} km/h`], ['Humidity', `${Math.round(Number(current.humidity || 0))}%`]]
      .forEach(([label, value]) => {
        const item = el('div', 'ecc-weather-metric');
        item.append(el('span', '', label), el('strong', '', value));
        metrics.append(item);
      });

    const hours = el('div', 'ecc-weather-hours');
    (weather.hourly || []).slice(0, 12).forEach((hour) => {
      const item = el('div', 'ecc-weather-hour');
      item.append(el('span', '', weatherTime(hour.time, weather.timezone_offset)));
      item.append(weatherIcon(hour.icon, hour.description, 'ecc-weather-hour-icon'));
      item.append(el('strong', '', temperature(hour.temperature)));
      hours.append(item);
    });

    const forecast = el('div', 'ecc-weather-forecast');
    forecast.append(el('div', 'ecc-weather-forecast-title', 'Next 12 Hours'), hours);
    root.append(currentBlock, metrics, forecast);
    panel.replaceChildren(root);
  };

  document.querySelectorAll('[data-ecc-weather-url]').forEach(async (panel) => {
    try {
      const response = await fetch(panel.dataset.eccWeatherUrl, {cache: 'no-store', headers: {Accept: 'application/json'}});
      const result = await response.json();
      if (!response.ok || !result.ok || !result.weather) throw new Error(result.message || 'Weather unavailable');
      renderWeather(panel, result.weather);
    } catch (error) {
      panel.replaceChildren(el('p', 'ecc-weather-error', 'Weather is unavailable. Check the ECC display weather settings.'));
    }
  });

  window.setTimeout(() => window.location.reload(), 300000);
})();
