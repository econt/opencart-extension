# <div align="center">📦 Econt Express за OpenCart 3 и OpenCart 4</div>

## <div align="center">Официална документация за интеграция с Econt Express</div>


# Econt за OpenCart — Уики (Начало)

<div align="center">
  <img src="/wiki/images/econt-logo.png" alt="Econt Logo" height="100">
  <img src="/wiki/images/opencart-logo.png" alt="OpenCart Logo" height="80">
</div>

# 📖 Преглед
Официалният Econt Opencart плъгин предоставя пълна интеграция между вашия Opencart магазин и системата за доставки на Econt Express.
С този плъгин можете да автоматизирате изцяло процеса на доставка и да предоставите на клиентите си професионално изживяване при поръчките.

# 🧩 OpenCart 3 и OpenCart 4

Модулът има отделен пакет за всяка версия на OpenCart. Двата пакета са с обща версия и излизат в един и същ
[release](https://github.com/econt/opencart-extension/releases/latest).

|                         | OpenCart 3 (3.0.x)                                                                                                                  | OpenCart 4 (4.0+)                                                                                                                           |
|-------------------------|-------------------------------------------------------------------------------------------------------------------------------------|---------------------------------------------------------------------------------------------------------------------------------------------|
| **Пакет**               | [econt_delivery.ocmod.zip](https://github.com/econt/opencart-extension/releases/latest/download/econt_delivery.ocmod.zip)           | [econt_delivery_oc4.ocmod.zip](https://github.com/econt/opencart-extension/releases/latest/download/econt_delivery_oc4.ocmod.zip)           |
| **PHP**                 | 7.4+                                                                                                                                | 8.0+                                                                                                                                        |
| **Инсталация**          | Extensions → Installer, после Extensions → Extensions → Shipping                                                                    | Extensions → Installer, после Extensions → Extensions → Shipping                                                                            |
| **Ръчна инсталация**    | `oc3/admin` и `oc3/catalog` от репото се копират в главната директория на магазина                                                  | Не се поддържа: OpenCart 4 зарежда разширението само ако е качено през Installer. Името на файла трябва да е точно `econt_delivery_oc4.ocmod.zip` |
| **Код в репото**        | [`oc3/`](oc3)                                                                                                                       | [`oc4/`](oc4)                                                                                                                               |

Подробно: [🛠️ Инсталация](https://github.com/econt/opencart-extension/wiki/Installation).

# ⚡ Бърз старт - За 10 минути до готова интеграция
| 📋 Стъпка | 📄 Ръководство                    | ⏱️ Време | 🎯 Резултат         |
|-----------|-----------------------------------|----------|---------------------|
| **1**     | [🛠️ Инсталация](https://github.com/econt/opencart-extension/wiki/Installation) | 2        | Инсталиран плъгин   |
| **2**     | [⚙️ Настройки](https://github.com/econt/opencart-extension/wiki/Settings)  | 3        | Активиран плъгин    |
| **3**     | [🚚 Доставка](https://github.com/econt/opencart-extension/wiki/Delivery)   | 5        | Настроено за работа |
| **4**     | [📦 Продукти](https://github.com/econt/opencart-extension/wiki/Products)   | ?        | Готови за продажба  |

> 💡 Съвет: Започнете с Demo режим за безопасно тестване на интеграцията

# 🎯 Основни функции

---

## 💰 Автоматично ценообразуване
Калкулиране на цени в реално време според тегло и адрес
Поддръжка за различни типове доставка
Автоматично обновяване при промяна на параметрите

---

### 🖥️ **Системни Изисквания**

| 🔧 Компонент    | ✅ Минимум      | ⭐ Препоръчано    | 🔍 Как да проверя                                          |
|-----------------|----------------|------------------|------------------------------------------------------------|
| **OpenCart**    | 3.0.x или 4.0+ | Последна версия  | Admin -> Dashboard (най-долу в страницата)                 |
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

# 🏁 Започване
- [📥 Инсталация](https://github.com/econt/opencart-extension/wiki/Installation) - Стъпка по стъпка инсталация на плъгина
- [⚙️ Първоначална настройка](https://github.com/econt/opencart-extension/wiki/Settings) - API конфигурация и основни настройки
- [📦 Настройка на продукти](https://github.com/econt/opencart-extension/wiki/Products) - Конфигуриране на тегла и параметри
## 🔧 Поддръжка
- [🛠️ Отстраняване на проблеми](https://github.com/econt/opencart-extension/wiki/Troubleshooting) - Решения за чести проблеми и диагностика
- 📸 Визуален гид
  Всички страници съдържат детайлни скрийншоти от всяка стъпка на настройката за по-лесно следване на инструкциите.

# 👩‍💻 Разработка

```
oc3/          модулът за OpenCart 3 (admin/, catalog/)
oc4/          модулът за OpenCart 4 (admin/, catalog/, install.json)
VERSION       версията на двата пакета
build.php     прави dist/econt_delivery.ocmod.zip и dist/econt_delivery_oc4.ocmod.zip
wiki/         копие на страниците от GitHub wiki
```

Нова версия:

1. Версията се сменя във `VERSION` (само там).
2. `php build.php` прави двата zip-а в `dist/`. Версията влиза в `install.xml` (OpenCart 3) и в `install.json`
   (OpenCart 4); `oc4/install.json` се обновява и се commit-ва заедно с `VERSION`.
3. Release с tag `vX.Y.Z` (например `v1.7.8`) и **двата** zip-а. Tag-ът е само с версията: проверката за нова
   версия в админа на OpenCart 3 маха всичко освен цифрите и точките. Линкът
   `releases/latest/download/econt_delivery.ocmod.zip` в Достави с Еконт сочи към последния release, затова
   всеки release трябва да съдържа и zip-а за OpenCart 3.
4. Двата zip-а се качват и на static-content.econt.com.

# 🏁 Пълна документация
- [Документация](https://github.com/econt/opencart-extension/wiki)

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

## ❓ Нуждаете се от помощ?

ℹ️ Нашите експерти могат да ви предоставят информация за системите на Econt или настройките на вашия акаунт след инсталирането на модула.

🔧 Ако се нуждаете от помощ при инсталирането, настройките на системата или техническа поддръжка, те ще ви информират какви условия се прилагат за разработчика, за да разреши проблема.

📧 Ако имате въпроси или се нуждаете от помощ при интеграциите, мoжете да се свържете с нас на [support_integrations@econt.com](mailto:support_integrations@econt.com).

📚 За повече информация разгледайте секцията [Troubleshooting](https://github.com/econt/opencart-extension/wiki/Troubleshooting.md)

---

<div align="center">
🌟 Харесва ли ви плъгинът?
<br/>
Дайте ⭐ звезда на <a href="https://github.com/econt/opencart-extension">GitHub</a> и споделете с други разработчици!
</div>

---
