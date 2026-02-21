# Invoice Automation System

This directory contains scripts for automatic invoice generation and payment reminders.

## Files

- `invoice_and_reminders.php` - Main automation script that generates invoices and sends reminders

## Features

### Automatic Invoice Generation
- Generates invoices automatically for active leases based on payment frequency
- **Monthly leases**: Invoice generated on the 1st of each month for next month's rent
- **Quarterly leases**: Invoice generated at start of quarter for next quarter  
- **Yearly leases**: Invoice generated at start of year for next year
- Creates separate invoices for rent and service charge (if applicable)
- Prevents duplicate invoices for the same period
- Only processes active leases within their valid date range

### Reminder Notifications
- **7 days before due**: First reminder notification
- **3 days before due**: Second reminder notification
- **Due date**: Final reminder notification
- **Overdue invoices**: Weekly reminders (every 7 days) until paid

## Setup Instructions

### Option 1: Windows Task Scheduler (Recommended for XAMPP)

1. Open Task Scheduler (search "Task Scheduler" in Windows)
2. Click "Create Basic Task"
3. Name: "EstatePro Invoice Automation"
4. Trigger: Daily, at 00:05 (or your preferred time)
5. Action: Start a program
6. Program: `C:\xampp\php\php.exe`
7. Arguments: `C:\xampp\htdocs\ESTATEMANAGEMENT\app\cron\invoice_and_reminders.php`
8. Start in: `C:\xampp\htdocs\ESTATEMANAGEMENT`
9. Finish

### Option 2: Linux Cron

Add to crontab (`crontab -e`):

```bash
# Run daily at 00:05
5 0 * * * /usr/bin/php /path/to/ESTATEMANAGEMENT/app/cron/invoice_and_reminders.php
```

### Option 3: External Cron Service

Use a service like cron-job.org or EasyCron:

- URL: `http://yourdomain.com/app/cron/invoice_and_reminders.php?key=YOUR_SECRET_KEY`
- Frequency: Daily
- Time: 00:05 (or your preferred time)

**Important**: Change the secret key in `invoice_and_reminders.php` before using this method!

### Option 4: Manual Execution

You can run the script manually:

**Via Command Line:**
```bash
php app/cron/invoice_and_reminders.php
```

**Via Web Browser:**
```
http://yourdomain.com/app/cron/invoice_and_reminders.php?key=YOUR_SECRET_KEY
```

**Via Admin Panel:**
Go to Admin → Invoice Automation → Click "Run Invoice Generation & Reminders"

## Security

- The script requires a secret key when accessed via web
- Change `CHANGE_THIS_SECRET_KEY_IN_PRODUCTION` in `invoice_and_reminders.php` to a strong random string
- When running via cron/Task Scheduler, the key is not required (CLI mode)

## Testing

1. Create a test lease with `status = 'active'` and `payment_frequency = 'monthly'`
2. Run the script manually via admin panel or command line
3. Check that invoices are generated correctly
4. Verify notifications are sent to tenants

## Troubleshooting

**No invoices generated:**
- Check that leases have `status = 'active'`
- Verify lease dates are valid (start_date <= today <= end_date)
- Ensure rent_amount or service_charge > 0
- Check for existing invoices for the same period (script prevents duplicates)

**Reminders not sent:**
- Verify notifications table exists
- Check tenant user_id is valid
- Ensure invoice status is 'pending' or 'partial'
- Check that invoice due_date is within reminder window

**Script errors:**
- Check PHP error logs
- Verify database connection
- Ensure all required tables exist
- Check file permissions

## Logs

The script outputs:
- Success messages for each invoice generated
- Success messages for each reminder sent
- Error messages for any failures
- Execution time

When run via CLI, output goes to console.
When run via web, output is JSON format.
