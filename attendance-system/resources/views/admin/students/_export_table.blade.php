<div style="font-family: Arial, Helvetica, sans-serif; font-size:12px;">
    <h2>Students Export</h2>
    <table style="width:100%; border-collapse: collapse;">
        <thead>
            <tr>
                <th style="border:1px solid #ddd; padding:8px; text-align:left;">Student ID</th>
                <th style="border:1px solid #ddd; padding:8px; text-align:left;">First Name</th>
                <th style="border:1px solid #ddd; padding:8px; text-align:left;">Last Name</th>
                <th style="border:1px solid #ddd; padding:8px; text-align:left;">Email</th>
                <th style="border:1px solid #ddd; padding:8px; text-align:left;">Phone</th>
                <th style="border:1px solid #ddd; padding:8px; text-align:left;">Department</th>
                <th style="border:1px solid #ddd; padding:8px; text-align:left;">Semester</th>
                <th style="border:1px solid #ddd; padding:8px; text-align:left;">Active</th>
                <th style="border:1px solid #ddd; padding:8px; text-align:left;">Registered At</th>
            </tr>
        </thead>
        <tbody>
            @foreach($students as $s)
                <tr>
                    <td style="border:1px solid #ddd; padding:8px;">{{ $s->student_id }}</td>
                    <td style="border:1px solid #ddd; padding:8px;">{{ $s->first_name }}</td>
                    <td style="border:1px solid #ddd; padding:8px;">{{ $s->last_name }}</td>
                    <td style="border:1px solid #ddd; padding:8px;">{{ $s->email }}</td>
                    <td style="border:1px solid #ddd; padding:8px;">{{ $s->phone }}</td>
                    <td style="border:1px solid #ddd; padding:8px;">{{ $s->department }}</td>
                    <td style="border:1px solid #ddd; padding:8px;">{{ $s->semester }}</td>
                    <td style="border:1px solid #ddd; padding:8px;">{{ $s->is_active ? 'Yes' : 'No' }}</td>
                    <td style="border:1px solid #ddd; padding:8px;">{{ optional($s->created_at)->toDateTimeString() }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
