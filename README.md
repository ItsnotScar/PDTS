# PDTS - Politeknik Dormitory Ticketing System

## Overview
PDTS is a web-based dormitory ticketing system designed to manage student complaints and maintenance requests within a polytechnic hostel environment.  
The system supports role-based access for students, administrators, and technicians to streamline complaint submission, assignment, and resolution tracking.

---

## Features

- Student complaint submission system  
- Role-based access control (Student, Admin, Technician, Supervisor)  
- Admin dashboard for managing and monitoring complaints  
- Technician assignment for handling maintenance tasks  
- Complaint status tracking (Pending, In Progress, Completed, Rejected)  
- File attachment support for evidence submission  

---

## Tech Stack

- PHP (Backend)
- MySQL (Database)
- HTML, CSS, JavaScript (Frontend)
- Apache (Local Server via XAMPP)

---

## Database

The system uses a MySQL database to manage:
- User profiles and roles  
- Complaints and workflow tracking  
- File attachments  
- System warnings and automation logic  

A clean database schema file is included in this repository:
- `database_schema.sql`

---

## How to Run the Project

1. Clone this repository
2. Move the project folder into your `htdocs` directory (XAMPP)
3. Start Apache and MySQL in XAMPP
4. Import `database_schema.sql` into phpMyAdmin
5. Open your browser and go to:
http://localhost/your-project-folder

## Notes

- This system was developed as part of an academic project.  
- The database schema is provided for setup and testing purposes.  
- No production or sensitive data is included in this repository.
