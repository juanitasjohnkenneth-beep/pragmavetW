// SAAS Calendar Component
// Depends on React, ReactDOM, Tailwind, and Lucide Icons available in the global scope.
// Expects window.CURRENT_USER = { id: 1, role: 'admin' }
// Expects window.CALENDAR_EVENTS = [...]

const Icon = ({ name, size = 20, className = "" }) => <i data-lucide={name} className={className} style={{ width: size, height: size }}></i>;

const getColorClasses = (color) => {
    switch(color) {
        case 'green': return 'bg-emerald-50 text-emerald-700 border-emerald-200 hover:bg-emerald-100 shadow-sm';
        case 'blue': return 'bg-blue-50 text-blue-700 border-blue-200 hover:bg-blue-100 shadow-sm';
        case 'orange': return 'bg-orange-50 text-orange-700 border-orange-200 hover:bg-orange-100 shadow-sm';
        case 'purple': return 'bg-purple-50 text-purple-700 border-purple-200 hover:bg-purple-100 shadow-sm';
        case 'gray': return 'bg-slate-50 text-slate-600 border-slate-200 hover:bg-slate-100 shadow-sm';
        case 'red': return 'bg-red-50 text-red-700 border-red-200 hover:bg-red-100 shadow-sm';
        default: return 'bg-slate-50 text-slate-700 border-slate-200 shadow-sm';
    }
};

const EventPill = ({ event, onClick, role }) => {
    return (
        <div 
            onClick={(e) => { e.stopPropagation(); onClick(event); }}
            className={`event-pill flex items-center gap-1.5 px-2 py-1.5 mb-1 rounded-md border text-[11px] font-semibold w-full truncate ${getColorClasses(event.color)}`}
            style={{transition: 'transform 0.15s ease', cursor: 'pointer', borderWidth: '1px', borderStyle: 'solid'}}
            title={`${event.time} - ${event.title} (${event.pet})`}
        >
            <div className="flex-shrink-0 flex items-center justify-center w-3 h-3 rounded-full bg-white bg-opacity-60 text-current">
                <Icon name={event.icon} size={10} className="stroke-[3px]" />
            </div>
            <span className="truncate">
                {role === 'client' 
                    ? `${event.time.split(' ')[0]} - ${event.pet}` 
                    : (event.status === 'Leave' ? event.title : `${event.time.split(' ')[0]} ${event.pet} - ${event.title}`)}
            </span>
        </div>
    );
};

const DayCell = ({ day, isCurrentMonth, isToday, events, onEventClick, role }) => {
    const displayEvents = events.slice(0, role === 'client' ? 4 : 3);
    const overflowCount = events.length > displayEvents.length ? events.length - displayEvents.length : 0;

    return (
        <div className={`day-cell min-h-[120px] p-2 border-r border-b border-slate-200 relative group flex flex-col ${!isCurrentMonth ? 'bg-slate-50/50' : 'bg-white'} ${isToday ? 'bg-blue-50/30' : ''}`}
             style={{borderRightWidth: '1px', borderBottomWidth: '1px', borderStyle: 'solid'}}>
            <div className="flex justify-between items-start mb-2">
                <span className={`text-sm font-semibold w-7 h-7 flex items-center justify-center rounded-full ${isToday ? 'bg-brand-600 text-white shadow-md' : (isCurrentMonth ? 'text-slate-700' : 'text-slate-400')}`}>
                    {day}
                </span>
                {events.length > 0 && isCurrentMonth && role !== 'client' && (
                    <button className="opacity-0 group-hover:opacity-100 text-slate-400 hover:text-brand-600 transition-opacity focus:outline-none">
                        <Icon name="plus" size={16} />
                    </button>
                )}
            </div>
            
            <div className="flex flex-col gap-0.5 custom-scrollbar overflow-y-auto flex-grow max-h-[85px]">
                {displayEvents.map(evt => (
                    <EventPill key={evt.id} event={evt} onClick={onEventClick} role={role} />
                ))}
                {overflowCount > 0 && (
                    <div className="text-[11px] font-medium text-slate-500 pl-1 mt-0.5 hover:text-brand-600 cursor-pointer">
                        +{overflowCount} more
                    </div>
                )}
            </div>
        </div>
    );
};

const UniversalCalendarSystem = () => {
    const role = window.CURRENT_USER.role;
    const userId = window.CURRENT_USER.id;
    const [eventsList, setEventsList] = React.useState(window.CALENDAR_EVENTS || []);

    const events = React.useMemo(() => {
        return eventsList.filter(event => {
            if (role === 'admin') return true;
            if (role === 'doctor') return event.doctorId == userId || event.ownerId == userId; // Some flexibility just in case
            if (role === 'client') return event.ownerId == userId;
            return false;
        });
    }, [eventsList, role, userId]);

    React.useEffect(() => {
        if(window.lucide) window.lucide.createIcons();
    });
    
    // Custom trigger logic linking to the native PHP Modal logic
    const handleEventClick = (evt) => {
        if(evt.status === 'Leave') return; // Leaves don't have modals normally in the UI, or we can build one if needed
        
        // Pass data to global native PHP modal trigger
        if(typeof window.triggerModal === 'function') {
            window.triggerModal(evt.appt_id, evt.title, evt.owner, evt.pet, evt.raw_obj.service_type, evt.raw_obj.time, evt.status, evt.raw_obj.created_at, evt.raw_obj.approved_at, evt.raw_date);
        } else if (typeof window.openApptModal === 'function') {
            // Client dashboard has openApptModal
            window.openApptModal(evt.raw_obj);
        } else {
            console.warn("No modal hook found for event click", evt);
        }
    };

    // Very simple calendar math (using current month/year just for demo structure or actual real dates!)
    // For a fully dynamic calendar we calculate true month pad:
    const now = new Date();
    const currentYear = now.getFullYear();
    const currentMonth = now.getMonth(); // 0-11
    
    const firstDay = new Date(currentYear, currentMonth, 1).getDay(); // 0-6
    const daysInMonth = new Date(currentYear, currentMonth + 1, 0).getDate();
    const daysInPrevMonth = new Date(currentYear, currentMonth, 0).getDate();

    const gridDays = [];
    // Previous month padded days
    for(let i=0; i<firstDay; i++) {
        let d = daysInPrevMonth - firstDay + i + 1;
        gridDays.push({ day: d, current: false, fullDate: null });
    }
    // Current month days
    for(let i=1; i<=daysInMonth; i++) {
        let padM = (currentMonth+1).toString().padStart(2, '0');
        let padD = i.toString().padStart(2, '0');
        gridDays.push({ day: i, current: true, fullDate: `${currentYear}-${padM}-${padD}` });
    }
    // Next month padded days
    const totalCells = 42; 
    let remaining = totalCells - gridDays.length;
    for(let i=1; i<=remaining; i++) {
        gridDays.push({ day: i, current: false, fullDate: null });
    }

    return (
        <div className={`flex flex-col md:flex-row min-h-[700px] h-full ${role === 'client' ? 'bg-transparent' : 'bg-transparent'}`}>
            
            {/* UI DIFFERENCE: Admin/Doctor get Sidebar Filters */}
            {(role === 'admin' || role === 'doctor') && (
                <div className="w-64 bg-white border-r border-slate-200 hidden md:flex flex-col relative rounded-l-2xl" style={{borderRightStyle: 'solid', borderRightWidth: '1px'}}>
                    <div className="px-4 py-6">
                        <p className="text-xs font-bold text-slate-400 uppercase tracking-widest mb-3 px-2">Data Filters</p>
                        <div className="space-y-1">
                            <label className="flex items-center gap-3 p-2 rounded-lg hover:bg-slate-50 cursor-pointer text-sm font-medium text-slate-700">
                                <input type="checkbox" defaultChecked className="text-brand-600" /> Consultations
                            </label>
                            <label className="flex items-center gap-3 p-2 rounded-lg hover:bg-slate-50 cursor-pointer text-sm font-medium text-slate-700">
                                <input type="checkbox" defaultChecked className="text-emerald-600" /> Vaccinations
                            </label>
                            <label className="flex items-center gap-3 p-2 rounded-lg hover:bg-slate-50 cursor-pointer text-sm font-medium text-slate-700">
                                <input type="checkbox" defaultChecked className="text-orange-600" /> Surgeries
                            </label>
                        </div>
                    </div>
                </div>
            )}

            <div className={`flex-1 flex flex-col h-full overflow-hidden ${role === 'client' ? 'w-full' : ''}`}>
                {/* Header Area */}
                <header className={`bg-transparent flex items-center justify-between pb-4 ${role !== 'client' ? 'px-6 pt-4' : ''}`}>
                    <div className="flex items-center gap-4">
                        <div>
                            <h2 className="text-2xl font-bold text-slate-800 tracking-tight">
                                {now.toLocaleString('default', { month: 'long' })} {currentYear}
                            </h2>
                            {role === 'client' && <p className="text-slate-500 text-sm mt-0.5">My Pets & Scheduling Dashboard</p>}
                        </div>
                    </div>

                    <div className="flex items-center gap-4">
                        {role === 'admin' && (
                            <div className="relative hidden lg:block">
                                <Icon name="search" size={16} className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
                                <input type="text" placeholder="Search Master DB..." className="pl-9 pr-4 py-2 bg-white border border-slate-200 rounded-full text-sm w-64 outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-200" />
                            </div>
                        )}
                    </div>
                </header>

                {/* Main Content Area */}
                <main className={`flex-1 overflow-y-auto custom-scrollbar flex flex-col ${role !== 'client' ? 'px-6 pb-6' : ''}`}>
                    {/* UI DIFFERENCE: Analytics Cards for Admin/Doctor ONLY */}
                    {(role === 'admin' || role === 'doctor') && (
                        <div className="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
                            <div className="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm flex items-center justify-between">
                                <div><p className="text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">Scheduled Total</p><h3 className="text-2xl font-black text-slate-800">{events.length}</h3></div>
                                <div className="w-12 h-12 rounded-full bg-brand-50 text-brand-600 flex items-center justify-center"><Icon name="calendar" size={24} /></div>
                            </div>
                            <div className="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm flex items-center justify-between">
                                <div><p className="text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">Requiring Action</p><h3 className="text-2xl font-black text-slate-800">{events.filter(e => e.status === 'Pending').length} Pending</h3></div>
                                <div className="w-12 h-12 rounded-full bg-orange-50 text-orange-600 flex items-center justify-center"><Icon name="alert-circle" size={24} /></div>
                            </div>
                            <div className="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm flex items-center justify-between">
                                <div><p className="text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">System Status</p><h3 className="text-2xl font-black text-emerald-600">Active</h3></div>
                                <div className="w-12 h-12 rounded-full bg-emerald-50 text-emerald-600 flex items-center justify-center"><Icon name="check-circle" size={24} /></div>
                            </div>
                        </div>
                    )}

                    {/* Calendar Component Grid */}
                    <div className={`bg-white rounded-2xl border border-slate-200 overflow-hidden flex flex-col flex-1 shadow-sm h-full`}>
                        <div className="grid grid-cols-7 border-b border-slate-200 bg-slate-50" style={{borderBottomStyle: 'solid', borderBottomWidth:'1px'}}>
                            {['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'].map((day, i) => (
                                <div key={day} className={`py-3 text-center text-[11px] font-bold uppercase tracking-widest text-slate-500 ${i !== 6 ? 'border-r border-slate-200' : ''}`} style={{borderRightStyle: i!==6 ? 'solid' : 'none', borderRightWidth:'1px'}}>
                                    {day}
                                </div>
                            ))}
                        </div>
                        <div className="grid grid-cols-7 flex-1">
                            {gridDays.map((cell, idx) => {
                                // Find events for this cell date
                                const dayEvents = cell.current && cell.fullDate ? events.filter(e => e.raw_date === cell.fullDate) : [];
                                // Is Today?
                                const isToday = cell.current && parseInt(cell.fullDate.split('-')[2]) === now.getDate();
                                return (
                                    <DayCell 
                                        key={idx} day={cell.day} isCurrentMonth={cell.current} isToday={isToday}
                                        events={dayEvents}
                                        onEventClick={handleEventClick}
                                        role={role}
                                    />
                                );
                            })}
                        </div>
                    </div>
                </main>
            </div>
        </div>
    );
};

const root = ReactDOM.createRoot(document.getElementById('react-saas-root'));
root.render(<UniversalCalendarSystem />);
