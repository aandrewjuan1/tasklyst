# TASKLYST: A WEB-BASED STUDENT TASK MANAGEMENT SYSTEM USING A HERMES 3 (3B) LLM ASSISTANT FOR INTELLIGENT PRIORITIZATION AND PREDICTIVE SCHEDULING

## Objectives of the Study

This study aims to design and develop a prototype of a web-based student task management system with a Hermes 3 (3B) Large Language Model (LLM) assistant for intelligent task prioritization and predictive scheduling.

### Specifically, the study seeks to:
1. **Develop a web-based task management system for EACC students** that supports academic and personal task organization and provides AI-assisted task prioritization using the Hermes 3 (3B) Large Language Model.
2. **Integrate the Hermes 3 (3B) Large Language Model** as a conversational assistant capable of generating predictive scheduling recommendations and explanations.
3. **Evaluate the system’s effectiveness and functional quality** in accordance with the ISO/IEC 25010 software quality standard, particularly in terms of improvements in students’ time management, task completion, and reduction of procrastination.

---

## Scope and Limitations

This study covers the design, development, and evaluation of **TaskLyst**, a web-based student task management system that integrates the Hermes 3 (3B) Large Language Model (LLM) to support intelligent task prioritization and predictive scheduling. The system is intended to help students organize academic and personal tasks, strengthen time management, and support productivity through LLM-assisted recommendations and a structured task workflow.

### Scope

- The study is limited to students of **Emilio Aguinaldo College–Cavite (EACC)** enrolled in Academic Year 2025–2026.  
- Faculty members and other non-student users are excluded.  
- Core system modules (implemented and evaluated):
  - **User Profile** — account registration, preferences, and basic personalization.
  - **LLM Module** — Hermes 3 (3B) LLM integration for conversational assistance, prioritization support, predictive scheduling recommendations, and explanation generation.
  - **Tasks** — task creation, categorization, priority assignment, deadline setting, and status tracking.
  - **Calendar** — calendar-based planning and timeline visualization.
  - **Dashboard** — summary view of active tasks, priorities, upcoming deadlines, and recommendations.
  - **Analytics** — basic productivity summaries and progress indicators (e.g., completion trends).
  - **Reminders** — configurable alerts and deadline notifications.
  - **Pomodoro** — focus-timer functionality and session logging.
  - **Collab Module** — basic shared tasks and chat-based coordination.

- **Feature coverage and exclusions:**
  - **Included:** task management, scheduling assistance, LLM-based conversational interaction, reminders, Pomodoro productivity support, basic analytics, and simple collaboration.  
  - **Excluded:** automated academic tutoring, motivational-coaching systems, enterprise-grade project management, and large-scale cross-institution task-sharing platforms.

### Limitations

- **LLM-related constraints:** Hermes 3 (3B) may produce inaccurate, inconsistent, or biased suggestions; outputs are treated as decision-support recommendations rather than authoritative instructions.
- **Participant and behavioral factors:** Individual differences in motivation, prior time-management skills, and engagement levels may affect measured outcomes.
- **Technical constraints:** Internet connectivity, device heterogeneity, and browser compatibility may influence usability and data completeness.
- **Evaluation constraints:** Limited timeframe and sample size may reduce statistical power and restrict long-term behavioral assessment; outcome measures combine system logs, self-reported feedback, and ISO/IEC 25010-based quality assessment, each with known limitations.

### Ethical Safeguards and Mitigation Strategies

- The study will secure institutional ethics approval and obtain informed consent.  
- Usage data will be anonymized and secured, with voluntary participation and opt-out options.  
- LLM variability will be mitigated by presenting recommendations as suggestions and logging user acceptance or rejection.  
- Technical mitigation includes cross-browser testing and minimum device requirements.  
- Measurement limitations will be addressed through triangulation of system logs, surveys, and ISO/IEC 25010 evaluations.

---

## METHODOLOGY
Research Design
This study employs Type 1 developmental research (product and program development) to systematically design, develop, and evaluate taskLyst—a web-based task management system with intelligent features to improve students' time management and task prioritization skills. Type 1 developmental research is a systematic investigation into the design, implementation, and evaluation of a specific educational product, program, or process. The purpose is to provide a tangible result and/or to identify principles or recommendations for improving the development of that particular project (Kholida, et al., 2025). According to Radcliffe and Kowalczyk (2023), developmental research design is suitable for analyzing and describing the product-development process, as well as evaluating the final product, investigating the product’s impact on learners or organizations, and conducting a general analysis of design development or evaluation processes, either as a whole or in parts. 
In line with this, the study also applies qualitative research methods to gain a deeper understanding of user experiences and perceptions regarding the developed system. Qualitative research investigates real-world problems by focusing on participants’ experiences, perceptions, and behaviors rather than numerical data, aiming to answer open-ended questions about the how and why of events (Tenny et al., 2022). This approach allows researchers to explore complex human behaviors and processes that are difficult to quantify, uncovering themes and patterns while maintaining the context and narrative. Furthermore, qualitative research complements developmental research by revealing insights into user satisfaction and usability challenges that may not be fully captured through quantitative measures.
To promote iterative system development, the web-based task management system will use the Agile Software Development Life Cycle (SDLC) framework. This methodology emphasizes adaptive planning, ongoing collaboration, and iterative improvement, with the project divided into planning, design, development, testing, release, and feedback cycles (GeeksforGeeks, 2025). This technique assures that taskLyst adapts to actual user needs and expert review. The development cycle will be split into 6 sprints, each should last 3 weeks.
 
Figure 2. Visualization of Agile SDLC by Aguayo’s Blog (2022)

1. Plan 

In this phase, the researchers will begin by identifying and defining the exact requirements needed for taskLyst. A product backlog will be created to outline and track the planned features of the system. The researchers current modules for taskLyst are as follows: user profile module; tasks management module; dashboard module; LLM Assistant module; and notifications module. The requirements will be guided by the system’s goal of helping students manage tasks, reduce procrastination, and improve productivity. As the development of this system progresses, the backlog will be continuously refined according to system performances and feedback gathered from future iterations.

2. Design

For the design phase, the researchers will design an intuitive and productivity-oriented interface, utilizing Tailwind CSS. The system’s interactivity will be implemented through Livewire 3, ensuring smooth real-time updates without reloading pages. The researchers will also use wireframes and digital design tools to visualize core features such as the task dashboard, calendar views, progress indicators, and LLM chat interface. The goal of this phase will be to ensure usability, clarity, and accessibility, allowing students to navigate their tasks seamlessly across modules and devices.

3. Develop

During the development stage, the backend will be built with Laravel 12, which is responsible for handling business logic, data processing, and module interactions. MySQL will be used for the database, storing user accounts, tasks, logs, user information, and system settings.
The LLM Assistant module will be integrated through PrismPHP connected to Ollama, using Hermes 3 model to assist users with task explanations, productivity guidance, scheduling, and other contextual queries. Each main module will be coded incrementally, to be consistent with the agile development principles.
Through the development of this system, continuous testing and debugging shall be conducted to ensure all components function as intended and maintain compatibility with one another.

4. Test

This stage should have the system undergo both manual and automated testing to ensure reliability, accuracy, and usability. Functional testing should verify that all modules perform according to the requirements, while interface and usability testing will assess whether users can easily navigate features, manage tasks, and interact with the LLM assistant. This phase will include integration testing to confirm proper functionality of Laravel, Livewire components, the database, and the LLM engine. Any bugs or usability concerns identified during the testing will be addressed, documented, and resolved before deployment.

5. Release

Once the testing has been completed, the system will be deployed in a live environment where users can access and interact with the application. The deployment will include configuring the Laravel backend, migrating the MySQL database, and preparing the Livewire-integrated frontend for production. In accordance with agile methodology, updates and improvements will be released to ensure users receive new features, optimizations, and bug fixes as soon as possible.

6. Feedback

Following the deployment of taskLyst, the researchers will review user feedback to evaluate the effectiveness of the developed task management system and identify areas for improvement. User behavior, system performance logs, and satisfaction surveys will be analyzed to determine if the system successfully improves time management and reduces procrastination. Feedback from this stage will inform future iterations and module upgrades, ensuring continuous improvement and long-term usability of the task management system.
The study will be executed at Emilio Aguinaldo College Cavite during the Academic Year 2025-2026, involving two groups of participants: (1) students from the Senior High School and College levels who will serve as end-users of the system, and (2) IT experts who will evaluate the system’s technical performance and software quality. The participants will be chosen using purposive and convenience sampling, ensuring that only those who actively use digital tools for academic and personal work management are included. All participants will get an informed consent form that outlines the study's goal, scope, and ethical considerations. The research will rigorously follow EAC Cavite's ethical standards, protecting the anonymity and voluntary involvement of all respondents.
Data will be collected using a standardized evaluation questionnaire based on the ISO/IEC 25010 software quality model, which assesses functionality, usability, dependability, performance efficiency, and security. A 4-point Likert scale will be used to measure participants' perceptions, while qualitative input will be evaluated thematically to provide more information about usability and satisfaction.

---

## Synthesis of Related Studies

The literature consistently documents that poor time management, procrastination, and executive-function deficits are major barriers to university student success, producing higher stress and lower academic attainment (Nasrullah & Khan, 2021; Luceño-Moreno et al., 2025). Meta-analyses and longitudinal work show that scaffolded interventions—structured goal setting, prioritized scheduling, and progress monitoring—yield measurable gains in task completion, perceived control, and GPA while reducing procrastination and stress (Nasrullah & Khan, 2021; H. Bond, 2024). Generic to-do lists and bare calendars do not supply these domain-specific affordances (Luceño-Moreno et al., 2025); instead, student-facing tools should provide deadline-aware planning, workload visualization, and time-management scaffolds to lower cognitive load and decision fatigue.

Complementary evidence from studies of digital productivity tools and time-management techniques shows that well-designed apps and methods (e.g., Pomodoro, focus timers, distraction blockers) increase focused work and reduce task-switching, and that analytic dashboards can guide personalized improvements (Cranefield et al., 2022; Pedersen et al., 2024; Zhang et al., 2021). However, these tools alone sometimes fail to adapt as students’ schedules and priorities change; empirical gaps remain in systems that seamlessly unify LMS/calendar integration, adaptive scheduling, and collaboration features tailored to student workflows (Oloyede & Ogunwale, 2022; Gamis, 2024). These findings argue for combining proven time-management scaffolds with adaptive, context-aware features rather than relying on static planners. 

Research on AI-enabled productivity tools indicates that intelligent assistants can personalize scheduling, automate repetitive planning tasks, and provide timely reminders that reduce late submissions and administrative load (Klimova & Pikhart, 2025; Rienties et al., 2025). Controlled and observational studies report improved on-time submission rates and measurable productivity gains, but they also document risks—technostress, over-reliance, algorithmic bias, and privacy concerns—so design must treat AI outputs as explainable decision-support with opt-in controls and explicit evaluation of well-being outcomes alongside performance metrics (Klimova & Pikhart, 2025; Sana Labs, 2025). 

A technical strand of the literature supports deploying compact, locally hostable models (≈3–4B parameters) for narrowly scoped agentic tasks—intent detection, task decomposition, structured output, and scheduling—when combined with schema constraints, function calling, and validator workflows (Sharma & Mehta, 2025; Kavathekar et al., 2025). Hermes 3 and similar fine-tuned instruct models demonstrate particularly strong instruction following, structured JSON/XML outputs, and multi-turn dialog capabilities that suit iterative scheduling and function-call integration; practitioner tooling such as Ollama further reduces friction for local deployment and experimentation (Nous Research, 2024; Udandarao & Misra, 2025). Privacy and institutional-sovereignty literatures therefore advocate local, small-model deployments to keep sensitive student data on campus infrastructure while maintaining responsive, low-latency interactions (Sandrini et al., 2025; UBC LT Hub, 2025). 

Taken together, these lines of evidence justify a privacy-first, local-first student productivity system that integrates LMS/calendar synchronization, deadline-aware prioritization, adaptive Pomodoro scaffolding, explainable LLM recommendations, and progress/ well-being dashboards. TaskLyst operationalizes this synthesis by combining Brightspace integration, a Hermes-backed conversational recommender (local via Ollama), adaptive Pomodoro timers, visual workload balancing, and evaluation metrics that assess both task performance and student well-being. This integrated approach addresses documented gaps in current tools—moving beyond passive task lists or cloud-dependent AI—to deliver an empirically grounded, deployable prototype tailored to university students while explicitly embedding transparency, user control, and data sovereignty in the system design (Nasrullah & Khan, 2021; Klimova & Pikhart, 2025; Sandrini et al., 2025).