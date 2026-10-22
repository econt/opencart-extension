<a name="top"></a>
 # Econt за OpenCart — Уики (Начало)
 
 <div align="center">
   <img src="images/econt-logo.png" alt="Econt Logo" height="80">&nbsp;&amp;&nbsp;<img src="images/opencart-logo.png" alt="OpenCart Logo" height="80">
 </div>
 
 <p align="center"><strong>Официална интеграция между OpenCart и услугата за доставки на Econt</strong></p>
 
 <p align="center">
   <a href="https://github.com/econt/opencart-extension"><img alt="Repo" src="https://img.shields.io/badge/GitHub-Repository-24292e?logo=github&logoColor=white"></a>
   <img alt="OpenCart" src="https://img.shields.io/badge/OpenCart-3.x%20|%204.x-1a73e8">
   <img alt="PHP" src="https://img.shields.io/badge/PHP-7.4%2B%20|%208.x-777bb4?logo=php&logoColor=white">
   <img alt="License" src="https://img.shields.io/badge/License-MIT-success">
 </p>
 
 ---
 
 ## 🔗 Бързи връзки
 - [🛠️ Инсталация](https://github.com/econt/opencart-extension/wiki/Installation)
 - [⚙️ Настройки](https://github.com/econt/opencart-extension/wiki/Settings)
 - [🚚 Доставка](https://github.com/econt/opencart-extension/wiki/Delivery)
 - [📦 Продукти](https://github.com/econt/opencart-extension/wiki/Products)
 - [🛠️ Ъпгрейд/Инсталиране на нова версия](https://github.com/econt/opencart-extension/wiki/Upgrade)
 - [🛠️ Отстраняване на проблеми](https://github.com/econt/opencart-extension/wiki/Troubleshooting)
 - [🐛 Съобщете проблем](https://github.com/econt/opencart-extension/issues/new)
 
 ---
 
 # 📖 Преглед
 Официалният Econt Opencart плъгин предоставя пълна интеграция между вашия Opencart магазин и системата за доставки на Econt Express. 
 С този плъгин можете да автоматизирате изцяло процеса на доставка и да предоставите на клиентите си професионално изживяване при поръчките.
 
 > 💡 Съвет: Започнете в Test режим, за да валидирате интеграцията безопасно, преди да активирате реални доставки.
 
 > 🧩 Модулът има отделен пакет за OpenCart 3 (`econt_delivery.ocmod.zip`) и за OpenCart 4 (`econt_delivery_oc4.ocmod.zip`).
 > Двата са с обща версия и са в [последния release](https://github.com/econt/opencart-extension/releases/latest).
 
 # ⚡ Бърз старт - За 10 минути до готова интеграция
 | 📋 Стъпка | 📄 Ръководство                    | ⏱️ Време | 🎯 Резултат         |
 |-----------|-----------------------------------|----------|---------------------|
 | **1**     | [🛠️ Инсталация](https://github.com/econt/opencart-extension/wiki/Installation) | 2        | Инсталиран плъгин   |
 | **2**     | [⚙️ Настройки](https://github.com/econt/opencart-extension/wiki/Settings)    | 3        | Активиран плъгин    |
 | **3**     | [🚚 Доставка](https://github.com/econt/opencart-extension/wiki/Delivery)     | 5        | Настроено за работа |
 | **4**     | [📦 Продукти](https://github.com/econt/opencart-extension/wiki/Products)     | ?        | Готови за продажба  |
 
 > ✅ Следвайки стъпките по-горе, ще имате напълно функционална интеграция само за няколко минути.
 
 # 🎯 Основни функции
 - ✅ <strong>Автоматично ценообразуване</strong> — реални цени според тегло, адрес и метод на доставка
 - 🏷️ <strong>Поддръжка на различни услуги</strong> — офис, автомат, до адрес, обратни връзки
 - 🧾 <strong>Товарителници</strong> — генериране и печат през админ панела (ако е приложимо)
 - 🔄 <strong>Проследяване</strong> — статуси и ъпдейти за пратки
 - 🧪 <strong>Test режим</strong> — безопасно тестване преди продукция
 
 ---
 
 ### 🖥️ **Системни Изисквания**
 
 | 🔧 Компонент    | ✅ Минимум      | ⭐ Препоръчано    | 🔍 Как да проверя                                          |
 |-----------------|----------------|------------------|------------------------------------------------------------|
 | **OpenCart**    | 3.0.x или 4.0.2.0+ | Последна версия  | Admin -> Dashboard (най-долу в страницата)                 |
 | **PHP**         | 7.4+ за OpenCart 3, 8.0+ за OpenCart 4 | 8.1+ | Проверете във Вашият CPanel или питайте Вашият разработчик |
 | **HTTPS/SSL**   | ✅ Задължително | Валиден SSL      | Адресната лента → 🔒                                       |
 
 ---
 
 ### 🏢 **Econt Изисквания**
 
 | 📋 Какво ви трябва | ✅ Статус | 📖 Инструкции |
 |-------------------|----------|---------------|
 | **Econt профил** | Безплатна регистрация | [👉 Регистрация тук](https://delivery.econt.com/) |
 | **API достъп** | Безплатен за клиенти | Получавате след регистрация |
 | **Активен договор** | За реални доставки | [👉 Информация тук](https://www.econt.com/solutions/online-stores) |
 
 ---
 
 ### 📦 **Изисквания за Продукти - Важно!**
 
 | 📋 Изискване | 🎯 Защо е важно | 🔧 Как да направя |
 |-------------|----------------|------------------|
 | **Тегло в килограми** | Без тегло няма да се калкулират цени | Продукти → Редактирай → Доставка → Тегло |
 | **Физически продукти** | Виртуални продукти не се доставят | Автоматично изключване |
 | **Вариации с тегло** | За променливи продукти | За всяка вариация отделно |
 
 ---
 
 # 🏁 Започване
 - [📥 Инсталация](https://github.com/econt/opencart-extension/wiki/Installation) - Стъпка по стъпка инсталация на плъгина
 - [⚙️ Първоначална настройка](https://github.com/econt/opencart-extension/wiki/Settings) - API конфигурация и основни настройки
 - [📦 Настройка на продукти](https://github.com/econt/opencart-extension/wiki/Products) - Конфигуриране на тегла и параметри
 
 ## 🔧 Поддръжка
 - [🛠️ Отстраняване на проблеми](https://github.com/econt/opencart-extension/wiki/Troubleshooting) - Решения за чести проблеми и диагностика
 - 🐛 [GitHub Issues: Съобщете проблем](https://github.com/econt/opencart-extension/issues/new)
 - 💬 Дискусии: GitHub Discussions
 
 <p align="right">[⬆️ Към началото](#top)</p>
 
 # 🆘 Получаване на помощ
 ## Официална поддръжка
 - 📧 Email: [support_integrations@econt.com](mailto:support_integrations@econt.com)
 - 🌐 Developer Portal: https://econt.com/developers
 ## Общностна поддръжка
 - 🐛 [GitHub Issues: Съобщете проблем](https://github.com/econt/opencart-extension/issues/new)
 - 💬 Дискусии: GitHub Discussions
 ## Преди да се свържете с поддръжката
 1. Проверете [Troubleshooting](https://github.com/econt/opencart-extension/wiki/Troubleshooting) страницата
 2. Претърсете съществуващите [Issues](https://github.com/econt/opencart-extension/issues)
 3. Подгответе системна информация и debug логове
 
 ---
 
 <div align="center">
 🌟 Харесва ли ви плъгинът?
 <br/>
 Дайте ⭐ звезда на GitHub и споделете с други разработчици!
 </div>
 
 ---
