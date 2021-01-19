# Тинькофф-Инвестиции: калькулятор реальной доходности

Скрипт считает доходность и процентную ставку по вашим инвестициям в Т—И. 

Из коробки в Инвестициях есть два способа оценить общую доходность портфеля:
- Прямо под текущей стоимостью написан доход за всё время — но это просто сумма изменений цены по всем бумагам, не учитывает дивиденды и купоны, а также проданные бумаги
- В портфельной аналитике можно также посмотреть доход за всё время (уже другой) — но он не учитывает период, когда Т—И были на бекенде БКС-Брокера

Кроме этого, было бы интересно численно сравнить инвестиции с обычным банковским вкладом.


## Скрипт делает следующее

1. Выгружает через API Тинькова все операции пополнения и вывода с брокерских счетов (считаются в сумме основной, ИИС и Инвесткопилка)
2. Выгружает текущую стоимость всех активов на брокерских счетах
3. `Суммарный профит = Текущая стоимость + Все выводы − Все пополнения`
4. Рассчитывает эквивалент % годовых, учитывая даты пополнений и выводов: под какой процент надо было бы положить деньги, чтобы был такой профит


## Как добыть API-ключ

Скрипт делает запросы к API, поэтому ему нужно скормить свежий ключ:
1. Залогиниться в Тинькофф-Банк
2. Открыть Инструменты разработчика → Application → Cookies → `https://www.tinkoff.ru`
3. Кукис с ключом называется `psid`, имеет вид `KzEJ4C63fNuM0O8S4WXDEXOlgFETl3Xg.m1-prod-api27`

⚠ Не показывайте ключ никому, он даёт доступ к вашим деньгам. Этому скрипту можно :)


## Как запускать

    root@example:~/tinkoff# ./tinkoff_real_invest_yield.php KzEJ4C63fNuM0O8S4WXDEXOlgFETl3Xg.m1-prod-api27
    [+] Getting invest accounts... "Tinkoff", "TinkoffIis"

    [+] Getting 'Tinkoff' cash holdings... 80.00 RUB, 108.87 USD
    [+] Getting 'Tinkoff' securities holdings... 1 762.79 USD, 52 367.54 RUB
    [+] Getting 'TinkoffIis' cash holdings... 11.36 RUB, 61.51 USD
    [+] Getting 'TinkoffIis' securities holdings... 5 876.45 USD, 223 251.16 RUB

    [+] Getting bank operations... 895 operations
    [+] Getting trading operations... 212 operations

    [i] USD: deposited +7 790.41 USD, withdrawn -2 618.63 USD
    [i] RUB: deposited +823 612.13 RUB, withdrawn -577 304.45 RUB
    [i] EUR: deposited +200.00 EUR, withdrawn -200.00 EUR

    [*] Currency USD
        Total profits: +2 637.84 USD
        Derived USD APY rate: 70.06 %

    [*] Currency RUB
        Total profits: +29 402.38 RUB
        Derived RUB APY rate: 10.32 %

    [*] Currency EUR
        Total profits: 0.00 EUR
        Derived EUR APY rate: 0 %

    [***] Overall converted to RUB
          Investments:   627 364.53 RUB
          Current value: 851 123.02 RUB
          Profits:       +223 758.48 RUB (+35.67 %)
